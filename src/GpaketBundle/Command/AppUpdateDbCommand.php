<?php

namespace GpaketBundle\Command;

use Doctrine\DBAL\DBALException;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityRepository;
use GpaketBundle\Entity\Chat;
use GpaketBundle\Entity\Log;
use GpaketBundle\Entity\Message;
use GpaketBundle\Entity\User;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AppUpdateDbCommand extends ContainerAwareCommand
{
	private $logs_id = [];
	private $log;
	/**
	 * @var EntityManager
	 */
	private $em;
	/**
	 * @var EntityRepository
	 */
	private $em_user;
	/**
	 * @var EntityRepository
	 */
	private $em_chat;
	/**
	 * @var EntityRepository
	 */
	private $em_log;
	/**
	 * @var EntityRepository
	 */
	private $em_message;

	protected function configure()
    {
        $this
            ->setName('app:update-db')
            ->setDescription('...')
            ->addArgument('argument', InputArgument::OPTIONAL, 'Argument description')
            ->addOption('option', null, InputOption::VALUE_NONE, 'Option description')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output) {
	    $args = $input->getArguments();
	    $this->em = $this->getContainer()->get('doctrine')->getEntityManager();

	    $count = (int) $this->em->createQuery('SELECT COUNT(l.update_id) FROM GpaketBundle\Entity\Log l')->getSingleScalarResult();
	    $by_page = $args['argument'] * 1000;
	    $n = ceil($count/$by_page) + 1;

	    $this->em_user = $this->em->getRepository('GpaketBundle:User');
	    $this->em_chat = $this->em->getRepository('GpaketBundle:Chat');
	    $this->em_log = $this->em->getRepository('GpaketBundle:Log');
	    $this->em_message = $this->em->getRepository('GpaketBundle:Message');
		$request = $this->em->createQueryBuilder()
					  ->select('l')
					  ->from('GpaketBundle\Entity\Log', 'l')
					  ->where('l.update_id >= :start AND l.update_id < :end');


	    for ($i = 1; $i<=$n; $i++) {
	    	$p = 10*$by_page;
	    	$start = $p * $i + 1;
	    	$end = $start + $p;
		    $logs = $request->setParameter('start', $start)
				            ->setParameter('end', $end)
				            ->getQuery()
				            ->getResult();

		    if (empty($logs)) {
			    $output->writeln('Skip.');
			    continue;
		    } else {
			    $output->writeln('Block.');
			    $output->writeln('Count of elements: ');
			    $output->writeln(count($logs));
		    }
			foreach ($logs as $l)
				$this->add($l);
		    $this->write($output);

	    }
    }

    private function write(OutputInterface $output) {
	    $connect_count = 0;
	    while($connect_count < 10) {
		    try {
			    $this->em->flush();
		    } catch(Exception $e) {
			    $output->writeln("Reconnect #$connect_count");
			    $this->em->getConnection()->close();
			    $this->em->getConnection()->connect();
		    }
		    $connect_count++;
	    }
    }

    private function add(Log $l) {
	    $log = json_decode($l->getRaw(), true);
	    $update_id = $log['update_id'];
	    if ($l->getUpdateId() == $update_id)
		    return;
	    elseif (array_search($update_id, $this->logs_id)!==false)
		    return;
	    elseif (!is_null($this->em_log->find($update_id)))
		    return;
	    $this->logs_id[] = $update_id;

	    if (isset($log['edited_message']))
		    $log['message'] = $log['edited_message'];

	    $this->log = $log;
	    $this->processLog($l);
	    $this->em->persist($l);
    }

	private function processMessage() {
		$message_id = $this->log['message']['message_id'];
		$message = $this->em_message->find($message_id);

		if (is_null($message)) {
			$message = new Message();
			$dt = new \DateTime();
			$dt->setTimestamp($this->log['message']['date']);
			$message->setMessageId($message_id);
			$message->setDate($dt);
			if (isset($this->log['message']['reply_to_message']))
				$message->setReplyToMessage($this->em_message->find($this->log['message']['reply_to_message']['message_id']));
			if (isset($this->log['message']['text']))
				$message->setText($this->log['message']['text']);
			$this->em->persist($message);
		}
		return $message;
	}

	private function processUser(Message $message) {
		$user_id = $this->log['message']['from']['id'];
		$user = $this->em_user->find($user_id);

		if (is_null($user)) {
			$user = new User();
			$user->setUserId($user_id);
			if (isset($this->log['message']['from']['first_name']))
				$user->setFirstName($this->log['message']['from']['first_name']);
			if (isset($this->log['message']['from']['last_name']))
				$user->setLastName($this->log['message']['from']['last_name']);
			if (isset($this->log['message']['from']['username']))
				$user->setUsername($this->log['message']['from']['username']);
			$message->setFrom($user);
			$this->em->persist($user);
		} elseif (is_null($message->getFrom()))
			$message->setFrom($user);
	}

	private function processChat(Message $message) {
		$chat_data = $this->log['message']['chat'];
		$chat = $this->em_chat->find($chat_data['id']);

		if (is_null($chat)) {
			$chat = new Chat();
			$chat->setChatId($chat_data['id']);
			$chat->setType($chat_data['type']);
			$this->em->persist($chat);
		}
		if (isset($chat_data['username']) && ($chat_data['username']!=$chat->getUsername())) {
			$chat->setUsername($chat_data['username']);
			$this->em->persist($chat);
		}
		if (isset($chat_data['title']) && ($chat_data['title']!=$chat->getTitle())) {
			$chat->setTitle($chat_data['title']);
			$this->em->persist($chat);
		}
		if (is_null($message->getChat())) {
			$message->setChat($chat);
			$this->em->persist($message);
		}
	}

	private function processLog(Log $l) {
		$message = $this->processMessage();

		$this->processUser($message);
		$this->processChat($message);
		$l->setMessage($message);
		$l->setUpdateId($this->log['update_id']);
	}

}
