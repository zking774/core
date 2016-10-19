<?php
/**
 * @author Joas Schilling <nickvergessen@owncloud.com>
 *
 * @copyright Copyright (c) 2015, ownCloud, Inc.
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace Tests\Core\Command\User;


use OC\Core\Command\User\LastSeen;
use OCP\User;
use Test\TestCase;


/**
 * Class LastSeenTest
 *
 * @group DB
 *
 * @package Test
 */
class LastSeenTest extends TestCase {
	/** @var \PHPUnit_Framework_MockObject_MockObject */
	protected $userManager;
	/** @var \PHPUnit_Framework_MockObject_MockObject[]|\OCP\Iuser[]  */
	protected $userMocks;
	/** @var \OCP\IDBConnection */
	protected $connection;
	/** @var \PHPUnit_Framework_MockObject_MockObject */
	protected $consoleInput;
	/** @var \PHPUnit_Framework_MockObject_MockObject */
	protected $consoleOutput;

	/** @var \Symfony\Component\Console\Command\Command */
	protected $command;

	protected function setUp() {
		parent::setUp();

		/** @var \OCP\IUserManager $userManager */
		$this->userManager = $this->getMockBuilder('OCP\IUserManager')
			->disableOriginalConstructor()
			->getMock();

		$this->userMocks = [];
		$this->userMocks['user1'] = $this->createMock('OCP\IUser');
		$this->userMocks['user1']->expects($this->any())
			->method('getDisplayName')
			->willReturn('User1 Name');
		$this->userMocks['user1']->expects($this->any())
			->method('getEMailAddress')
			->willReturn('user1@e.mail');

		$this->userMocks['user2'] = $this->createMock('OCP\IUser');
		$this->userMocks['user2']->expects($this->any())
			->method('getDisplayName')
			->willReturn('User2 Name');
		$this->userMocks['user2']->expects($this->any())
			->method('getEMailAddress')
			->willReturn('user2@e.mail');

		$this->connection = \OC::$server->getDatabaseConnection();
		$sql = $this->connection->getQueryBuilder();
		$sql->select('*')
			->from('preferences');
		$result = $sql->execute();
		$this->originalConfig = $result->fetchAll();
		$result->closeCursor();

		$sql = $this->connection->getQueryBuilder();
		$sql->delete('preferences');
		$sql->execute();

		$sql = $this->connection->getQueryBuilder();
		$sql->insert('preferences')
			->values([
				'userid' => $sql->createParameter('userid'),
				'appid' => $sql->createParameter('appid'),
				'configkey' => $sql->createParameter('configkey'),
				'configvalue' => $sql->createParameter('configvalue'),
			]);

		$sql->setParameters([
			'userid' => 'user',
			'appid' => 'login',
			'configkey' => 'lastLogin',
			'configvalue' => null,
		])->execute();
		$sql->setParameters([
			'userid' => 'user1',
			'appid' => 'login',
			'configkey' => 'lastLogin',
			'configvalue' => '1474453520',
		])->execute();
		$sql->setParameters([
			'userid' => 'user2',
			'appid' => 'login',
			'configkey' => 'lastLogin',
			'configvalue' => '1473067798',
		])->execute();

		$this->consoleInput = $this->createMock('Symfony\Component\Console\Input\InputInterface');
		$this->consoleOutput = $this->createMock('Symfony\Component\Console\Output\OutputInterface');

		$this->command = new LastSeen($this->userManager, $this->connection);
	}

	public function tearDown() {
		$sql = $this->connection->getQueryBuilder();
		$sql->delete('preferences');
		$sql->execute();

		$sql = $this->connection->getQueryBuilder();
		$sql->insert('preferences')
			->values([
				'userid' => $sql->createParameter('userid'),
				'appid' => $sql->createParameter('appid'),
				'configkey' => $sql->createParameter('configkey'),
				'configvalue' => $sql->createParameter('configvalue'),
			]);

		foreach ($this->originalConfig as $configs) {
			$sql->setParameter('userid', $configs['userid'])
				->setParameter('appid', $configs['appid'])
				->setParameter('configkey', $configs['configkey'])
				->setParameter('configvalue', $configs['configvalue']);
			$sql->execute();
		}

		parent::tearDown();
	}


	public function validUserLastSeenPlain() {
		return [
			['user', 'never logged in'],
			['user1', '21.09.2016 10:25'],
			['user2', '05.09.2016 09:29'],
		];
	}

	/**
	 * @dataProvider validUserLastSeenPlain
	 *
	 * @param string $userId
	 * @param string $displayName
	 * @param string $email
	 * @param string $expectedString
	 */
	public function testValidUserPlain($userId, $expectedString) {

		$this->expectConsoleInput($userId, null, null, 10, LastSeen::OUTPUT_FORMAT_PLAIN);

		$this->userManager->expects($this->any())
			->method('get')
			->with($userId)
			->willReturn($this->createMock('OCP\IUser'));

		$this->consoleOutput->expects($this->once())
			->method('writeln')
			->with($this->stringContains($expectedString));

		self::invokePrivate($this->command, 'execute', [$this->consoleInput, $this->consoleOutput]);
	}

	public function testInvalidUser() {

		$this->expectConsoleInput('user3', null, null, 10, LastSeen::OUTPUT_FORMAT_PLAIN);

		$this->userManager->expects($this->once())
			->method('get')
			->with('user3')
			->willReturn(null);

		$this->consoleOutput->expects($this->once())
			->method('writeln')
			->with($this->stringContains('does not exist'));

		self::invokePrivate($this->command, 'execute', [$this->consoleInput, $this->consoleOutput]);
	}


	public function validUserLastSeenJSON() {
		return [
			['user', 'User Name', 'user@e.mail',
				'[{"displayname":"User Name","email":"user@e.mail","lastLogin":null,"userid":"user"}]'],
			['user1','User1 Name', 'user1@e.mail',
				'[{"displayname":"User1 Name","email":"user1@e.mail","lastLogin":"2016-09-21T10:25:20Z","userid":"user1"}]'],
			['user2', 'User2 Name',	'user2@e.mail',
				'[{"displayname":"User2 Name","email":"user2@e.mail","lastLogin":"2016-09-05T09:29:58Z","userid":"user2"}]'],
		];
	}

	/**
	 * @dataProvider validUserLastSeenJSON
	 *
	 * @param string $userId
	 * @param string $displayName
	 * @param string $email
	 * @param string $expectedString
	 */
	public function testValidUserJSON($userId, $displayName, $email, $expectedString) {

		$user = $this->createMock('OCP\IUser');
		$user->expects($this->any())
			->method('getDisplayName')
			->willReturn($displayName);
		$user->expects($this->any())
			->method('getEMailAddress')
			->willReturn($email);

		$this->expectConsoleInput($userId, null, null, 10, LastSeen::OUTPUT_FORMAT_JSON);

		$this->consoleInput->expects($this->at(5))
			->method('getOption')
			->with('output')
			->willReturn(LastSeen::OUTPUT_FORMAT_JSON);

		$this->userManager->expects($this->any())
			->method('get')
			->with($userId)
			->willReturn($user);

		$this->consoleOutput->expects($this->once())
			->method('writeln')
			->with($this->stringContains($expectedString));

		self::invokePrivate($this->command, 'execute', [$this->consoleInput, $this->consoleOutput]);
	}

	public function validUsersJSON() {
		return [
			[null,
				'[{"displayname":"User1 Name","email":"user1@e.mail","lastLogin":"2016-09-21T10:25:20Z","userid":"user1"},{"displayname":"User2 Name","email":"user2@e.mail","lastLogin":"2016-09-05T09:29:58Z","userid":"user2"}]'],
			[true,
				'[{"displayname":"User2 Name","email":"user2@e.mail","lastLogin":"2016-09-05T09:29:58Z","userid":"user2"},{"displayname":"User1 Name","email":"user1@e.mail","lastLogin":"2016-09-21T10:25:20Z","userid":"user1"}]'],
		];
	}

	/**
	 * @dataProvider validUsersJSON
	 *
	 * @param string $leastRecent
	 * @param string $expectedString
	 */
	public function testValidUsersJSON($leastRecent, $expectedString) {

		$this->expectConsoleInput(null, $leastRecent, null, 10, LastSeen::OUTPUT_FORMAT_JSON);

		$this->consoleInput->expects($this->at(5))
			->method('getOption')
			->with('output')
			->willReturn(LastSeen::OUTPUT_FORMAT_JSON);

		if ($leastRecent) {
			$this->userManager->expects($this->at(0))
				->method('get')
				->with('user2')
				->willReturn($this->userMocks['user2']);
			$this->userManager->expects($this->at(1))
				->method('get')
				->with('user1')
				->willReturn($this->userMocks['user1']);
		} else {
			$this->userManager->expects($this->at(0))
				->method('get')
				->with('user1')
				->willReturn($this->userMocks['user1']);
			$this->userManager->expects($this->at(1))
				->method('get')
				->with('user2')
				->willReturn($this->userMocks['user2']);
		}
		$this->consoleOutput->expects($this->once())
			->method('writeln')
			->with($expectedString);

		self::invokePrivate($this->command, 'execute', [$this->consoleInput, $this->consoleOutput]);
	}

	public function invalidUsersJSON() {
		return [
			[null,
				'[{"displayname":"User1 Name","email":"user1@e.mail","lastLogin":"2016-09-21T10:25:20Z","userid":"user1"},{"displayname":null,"email":null,"lastLogin":"2016-09-05T09:29:58Z","userid":"user2"}]'],
			[true,
				'[{"displayname":null,"email":null,"lastLogin":"2016-09-05T09:29:58Z","userid":"user2"},{"displayname":"User1 Name","email":"user1@e.mail","lastLogin":"2016-09-21T10:25:20Z","userid":"user1"}]'],
		];
	}

	/**
	 * @dataProvider invalidUsersJSON
	 *
	 * @param string $leastRecent
	 * @param string $expectedString
	 */
	public function testInvalidUsersJSON($leastRecent, $expectedString) {

		$user1 = $this->createMock('OCP\IUser');
		$user1->expects($this->any())
			->method('getDisplayName')
			->willReturn('User1 Name');
		$user1->expects($this->any())
			->method('getEMailAddress')
			->willReturn('user1@e.mail');

		$this->expectConsoleInput(null, $leastRecent, null, 10, LastSeen::OUTPUT_FORMAT_JSON);

		$this->consoleInput->expects($this->at(5))
			->method('getOption')
			->with('output')
			->willReturn(LastSeen::OUTPUT_FORMAT_JSON);

		if ($leastRecent) {
			$this->userManager->expects($this->at(0))
				->method('get')
				->with('user2')
				->willReturn(null);
			$this->userManager->expects($this->at(1))
				->method('get')
				->with('user1')
				->willReturn($user1);
		} else {
			$this->userManager->expects($this->at(0))
				->method('get')
				->with('user1')
				->willReturn($user1);
			$this->userManager->expects($this->at(1))
				->method('get')
				->with('user2')
				->willReturn(null);

		}

		$this->consoleOutput->expects($this->once())
			->method('writeln')
			->with($expectedString);

		self::invokePrivate($this->command, 'execute', [$this->consoleInput, $this->consoleOutput]);
	}

	public function beforeUsersJSON() {
		return [
			['2 days ago', ['user1', 'user2'],
				'[{"displayname":"User1 Name","email":"user1@e.mail","lastLogin":"2016-09-21T10:25:20Z","userid":"user1"},{"displayname":"User2 Name","email":"user2@e.mail","lastLogin":"2016-09-05T09:29:58Z","userid":"user2"}]'],
			['2016-09-30', ['user1', 'user2'],
				'[{"displayname":"User1 Name","email":"user1@e.mail","lastLogin":"2016-09-21T10:25:20Z","userid":"user1"},{"displayname":"User2 Name","email":"user2@e.mail","lastLogin":"2016-09-05T09:29:58Z","userid":"user2"}]'],
			['2016-09-21', ['user2'],
				'[{"displayname":"User2 Name","email":"user2@e.mail","lastLogin":"2016-09-05T09:29:58Z","userid":"user2"}]'],
			['2016-09-05', [],
				'[]'],
		];
	}

	/**
	 * @dataProvider beforeUsersJSON
	 *
	 * @param string $leastRecent
	 * @param string[] $users userid => user the usermanager should know
	 * @param string $expectedString
	 */
	public function testBeforeUsersJSON($before, $users, $expectedString) {

		$this->expectConsoleInput(null, null, $before, 10, LastSeen::OUTPUT_FORMAT_JSON);

		$this->consoleInput->expects($this->at(5))
			->method('getOption')
			->with('output')
			->willReturn(LastSeen::OUTPUT_FORMAT_JSON);

		$pos = 0;
		foreach ($users as $userId) {
			$this->userManager->expects($this->at($pos++))
				->method('get')
				->with($userId)
				->willReturn($this->userMocks[$userId]);
		}
		$this->consoleOutput->expects($this->once())
			->method('writeln')
			->with($expectedString);

		self::invokePrivate($this->command, 'execute', [$this->consoleInput, $this->consoleOutput]);
	}

	private function expectConsoleInput ($uid, $leastRecent, $before, $limit = 10, $format = null) {
		$this->consoleInput->expects($this->at(0))
			->method('getArgument')
			->with('uid')
			->willReturn($uid);
		$this->consoleInput->expects($this->at(1))
			->method('getOption')
			->with('least-recent')
			->willReturn($leastRecent);
		$this->consoleInput->expects($this->at(2))
			->method('getOption')
			->with('before')
			->willReturn($before);
		$this->consoleInput->expects($this->at(3))
			->method('getOption')
			->with('limit')
			->willReturn($limit);
		$this->consoleInput->expects($this->at(4))
			->method('getOption')
			->with('output')
			->willReturn($format);
	}
}
