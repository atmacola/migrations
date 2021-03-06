<?php
/**
 * Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 * @link          http://cakephp.org CakePHP(tm) Project
 * @license       http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Migrations\Test\Command;

use Cake\Database\Schema\Collection;
use Cake\Datasource\ConnectionManager;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;
use Migrations\CakeManager;
use Migrations\Migrations;
use Migrations\MigrationsDispatcher;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * MarkMigratedTest class
 */
class StatusTest extends TestCase
{

    /**
     * Instance of a Symfony Command object
     *
     * @var \Symfony\Component\Console\Command\Command
     */
    protected $command;

    /**
     * Instance of a Phinx Config object
     *
     * @var \Phinx\Config\Config
     */
    protected $config = [];

    /**
     * Instance of a Cake Connection object
     *
     * @var \Cake\Database\Connection
     */
    protected $Connection;

    /**
     * Instance of a CommandTester object
     *
     * @var \Symfony\Component\Console\Tester\CommandTester
     */
    protected $commandTester;

    /**
     * Instance of a StreamOutput object.
     * It will store the output from the CommandTester
     *
     * @var \Symfony\Component\Console\Output\StreamOutput
     */
    protected $streamOutput;

    /**
     * setup method
     *
     * @return void
     */
    public function setUp()
    {
        parent::setUp();

        $this->Connection = ConnectionManager::get('test');
        $this->pdo = $this->Connection->driver()->connection();
        $this->Connection->execute('DROP TABLE IF EXISTS phinxlog');
        $this->Connection->execute('DROP TABLE IF EXISTS numbers');

        $application = new MigrationsDispatcher('testing');
        $this->command = $application->find('status');
        $this->streamOutput = new StreamOutput(fopen('php://memory', 'w', false));
    }

    /**
     * tearDown method
     *
     * @return void
     */
    public function tearDown()
    {
        parent::tearDown();
        $this->Connection->driver()->connection($this->pdo);
        $this->Connection->execute('DROP TABLE IF EXISTS phinxlog');
        $this->Connection->execute('DROP TABLE IF EXISTS numbers');
        unset($this->Connection, $this->command, $this->streamOutput);
    }

    /**
     * Test executing the "status" command
     *
     * @return void
     */
    public function testExecute()
    {
        $params = [
            '--connection' => 'test',
            '--source' => 'TestsMigrations'
        ];
        $commandTester = $this->getCommandTester($params);
        $commandTester->execute(['command' => $this->command->getName()] + $params);

        $display = $this->getDisplayFromOutput();
        $this->assertTextContains('down  20150704160200  CreateNumbersTable', $display);
        $this->assertTextContains('down  20150724233100  UpdateNumbersTable', $display);
        $this->assertTextContains('down  20150826191400  CreateLettersTable', $display);
    }

    /**
     * Test executing the "status" command with the JSON option
     *
     * @return void
     */
    public function testExecuteJson()
    {
        $params = [
            '--connection' => 'test',
            '--source' => 'TestsMigrations',
            '--format' => 'json'
        ];
        $commandTester = $this->getCommandTester($params);
        $commandTester->execute(['command' => $this->command->getName()] + $params);
        $display = $this->getDisplayFromOutput();

        $expected = '{"status":"down","id":"20150704160200","name":"CreateNumbersTable"},'
            . '{"status":"down","id":"20150724233100","name":"UpdateNumbersTable"},'
            . '{"status":"down","id":"20150826191400","name":"CreateLettersTable"}';

        $this->assertTextContains($expected, $display);
    }

    /**
     * Test executing the "status" command with the migrated migrations
     *
     * @return void
     */
    public function testExecuteWithMigrated()
    {
        $params = [
            '--connection' => 'test',
            '--source' => 'TestsMigrations'
        ];
        $this->getCommandTester($params);
        $migrations = $this->getMigrations();
        $migrations->migrate();

        $params = [
            '--connection' => 'test',
            '--source' => 'TestsMigrations'
        ];
        $commandTester = $this->getCommandTester($params);
        $commandTester->execute(['command' => $this->command->getName()] + $params);

        $display = $this->getDisplayFromOutput();
        $this->assertTextContains('up  20150704160200  CreateNumbersTable', $display);
        $this->assertTextContains('up  20150724233100  UpdateNumbersTable', $display);
        $this->assertTextContains('up  20150826191400  CreateLettersTable', $display);

        $migrations->rollback(['target' => 0]);
    }

    /**
     * Test executing the "status" command with inconsistency in the migrations files
     *
     * @return void
     */
    public function testExecuteWithInconsistency()
    {
        $params = [
            '--connection' => 'test',
            '--source' => 'TestsMigrations'
        ];
        $this->getCommandTester($params);
        $migrations = $this->getMigrations();
        $migrations->migrate();

        $migrationPaths = $migrations->getConfig()->getMigrationPaths();
        $migrationPath = array_pop($migrationPaths);
        $origin = $migrationPath . DS . '20150724233100_update_numbers_table.php';
        $destination = $migrationPath . DS . '_20150724233100_update_numbers_table.php';
        rename($origin, $destination);

        $params = [
            '--connection' => 'test',
            '--source' => 'TestsMigrations'
        ];
        $commandTester = $this->getCommandTester($params);
        $commandTester->execute(['command' => $this->command->getName()] + $params);

        $display = $this->getDisplayFromOutput();
        $this->assertTextContains('up  20150704160200  CreateNumbersTable', $display);
        $this->assertTextContains('up  20150724233100  UpdateNumbersTable  ** MISSING **', $display);
        $this->assertTextContains('up  20150826191400  CreateLettersTable', $display);

        rename($destination, $origin);

        $migrations->rollback(['target' => 0]);
    }

    /**
     * Gets a pre-configured of a CommandTester object that is initialized for each
     * test methods. This is needed in order to define the same PDO connection resource
     * between every objects needed during the tests.
     * This is mandatory for the SQLite database vendor, so phinx objects interacting
     * with the database have the same connection resource as CakePHP objects.
     *
     * @return \Symfony\Component\Console\Tester\CommandTester
     */
    protected function getCommandTester($params)
    {
        $input = new ArrayInput($params, $this->command->getDefinition());
        $this->command->setInput($input);
        $manager = new CakeManager($this->command->getConfig(), $input, $this->streamOutput);
        $manager->getEnvironment('default')->getAdapter()->setConnection($this->pdo);
        $this->command->setManager($manager);
        $commandTester = new \Migrations\Test\CommandTester($this->command);

        return $commandTester;
    }

    /**
     * Gets a Migrations object in order to easily create and drop tables during the
     * tests
     *
     * @return \Migrations\Migrations
     */
    protected function getMigrations()
    {
        $params = [
            'connection' => 'test',
            'source' => 'TestsMigrations'
        ];
        $migrations = new Migrations($params);
        $migrations
            ->getManager($this->command->getConfig())
            ->getEnvironment('default')
            ->getAdapter()
            ->setConnection($this->pdo);

        return $migrations;
    }

    /**
     * Extract the content that was stored in self::$streamOutput.
     *
     * @return string
     */
    protected function getDisplayFromOutput()
    {
        rewind($this->streamOutput->getStream());
        $display = stream_get_contents($this->streamOutput->getStream());
        return str_replace(PHP_EOL, "\n", $display);
    }
}
