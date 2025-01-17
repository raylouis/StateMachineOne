<?php /** @noinspection TypeUnsafeArraySearchInspection */
/** @noinspection NestedTernaryOperatorInspection */
/** @noinspection PhpPropertyOnlyWrittenInspection */
/** @noinspection SqlNoDataSourceInspection */
/** @noinspection SqlResolve */
/** @noinspection JsonEncodingApiUsageInspection */
/** @noinspection TypeUnsafeComparisonInspection */
/** @noinspection PhpUnused */
/** @noinspection PhpUnusedParameterInspection */
/** @noinspection SqlDialectInspection */

namespace eftec\statemachineone;

use DateTime;
use eftec\DocumentStoreOne\DocumentStoreOne;
use eftec\minilang\MiniLang;
use eftec\PdoOne;
use Exception;
use RuntimeException;

/**
 * Class StateMachineOne
 *
 * @package  eftec\statemachineone
 * @author   Jorge Patricio Castro Castillo <jcastro arroba eftec dot cl>
 * @version  2.23 2022-09-11
 * @license  LGPL-3.0 (you could use in a comercial-close-source product but any change to this library must be shared)
 * @link     https://github.com/EFTEC/StateMachineOne
 */
class StateMachineOne
{
    /** @var int NODB constants indicate that NO DB would be used */
    public const NODB = 0;
    /** @var int PDODB constants indicate that PdoOne database would be used */
    public const PDODB = 1;
    /** @var int PDODB constants indicate that DocumentStoreOne database would be used */
    public const DOCDB = 2;
    public $VERSION = '2.23';
    /**
     * @var array Possible states. It must be an associative array.<br>
     * <p>$statemachine->states=['State1'=>'name of the state','State2'=>'another name'];</p>
     */
    public $states = [];
    /** @var Transition[] */
    public $transitions = [];
    /** @var int Used to debug */
    public $currentTransition = -1;
    /** @var MiniLang[] */
    public $events = [];
    /** @var string[] */
    public $eventNames = [];
    /** @var string The name of the table to store the jobs */
    public $tableJobs = 'stm_jobs';
    /** @var string The name of the table to store the logs per job. If it's empty then it is not used */
    public $tableJobLogs = '';
    /** @var array The list of database columns used by the job */
    public $columnJobs = ['idjob', 'idparentjob', 'idactive', 'idstate', 'dateinit', 'datelastchange'
        , 'dateexpired', 'dateend'];
    /** @var array The List of database columns used by the log of the job */
    public $columnJobLogs = ['idjoblog', 'idjob', 'idrel', 'type', 'description', 'date'];
    /** @var array It indicates extra fields/states */
    public $fieldDefault = [''];
    /**
     * @var array (optional) it is used to indicate how to display the values in the web-ui<br>
     *                            <b>Example:</b>
     *                            <pre>
     *                            $this->fieldUI=['col'=>'READWRITE'
     *                            'col2'=>'WRITE'
     *                            'col3'=['Type1'=>20,'Type2=>40]]
     *                            ];
     *                            </pre>
     *                            <ul>
     *                            <li>READ :Read only values</li>
     *                            <li>READWRITE : Read and write value (default)</li>
     *                            <li>NUMERIC : Numeric value (integer or decimal)</li>
     *                            <li>ONOFF : ON(1) and OFF(0)</li>
     *                            <li>(array) : Dropdownlist using an associative array</li>
     *                            </ul>
     */
    public $fieldUI = [];
    /** @var MiniLang */
    public $miniLang;
    /** @var null|object It is the service class (optional) */
    public $serviceObject;
    /** @var string =['after','before','instead'][$i] */
    public $pauseTriggerWhen; // none
    /** @var callable|null It is used to replace the current method used to store the logs */
    public $customSaveDBJobLog;
    /** @var bool it's true if any value is changed during checkAllJobs() */
    public $changed = false;
    private $debug = false;
    private $debugAsArray = false;
    private $debugArray = [];
    /** @var bool */
    private $autoGarbage = false;
    private $counter;
    /** @var Job[] */
    private $jobQueue;
    /** @var Job[] */
    private $jobQueueBackup;
    /** @var int */
    private $defaultInitState = 0;
    /** @var null|int=[self::NODB,self::PDODB,self::DOCDB][$i] If the database is active. It is marked true every automatically when we set the database. */
    private $dbActive = 0;
    private $dbType = '';
    private $dbServer = '';
    private $dbUser = '';
    private $dbPassword = '';
    private $dbSchema = '';

    // callbacks
    /** @var PdoOne|null */
    private $pdoOne;
    /** @var DocumentStoreOne */
    private $docOne;
    /** @var callable it's called when we change state (by default it returns true) */
    private $changeStateTrigger;
    /** @var string =['after','before','instead'][$i] */
    private $changeStateTriggerWhen;
    /** @var callable it's called when we start the job (by default it returns true) */
    private $startTrigger;
    /** @var string =['after','before','instead'][$i] */
    private $startTriggerWhen;
    /** @var callable it's called when we pause the job (by default it returns true) */
    private $pauseTrigger;
    /** @var callable it's called when we stop the job (by default it returns true) */
    private $stopTrigger;
    /** @var string =['after','before','instead'][$i] */
    private $stopTriggerWhen;
    /** @var callable This function increased in 1 the next id of the job. It is only called if we are not using a database */
    private $getNumberTrigger;
    public $zeroDate = '1970-01-01 00:00:01';

    /**
     * Constructor of the class. By default, the construct set default triggers.
     * StateMachineOne constructor.
     *
     * @param null|object $serviceObject If we want to use a service class.
     * @param mixed       $miniLangClass [optionally] you can use a different mini language.
     * @noinspection UnusedConstructorDependenciesInspection
     */
    public function __construct(?object $serviceObject, $miniLangClass = MiniLang::class)
    {
        // reset values
        $this->jobQueue = [];
        $this->jobQueueBackup = [];
        $this->counter = 0;
        $this->changeStateTrigger = static function (StateMachineOne $smo, Job $job, $newState) {
            return true;
        };
        $this->startTrigger = static function (StateMachineOne $smo, Job $job) {
            return true;
        };
        $this->pauseTrigger = static function (StateMachineOne $smo, Job $job) {
            return true;
        };
        $this->stopTrigger = static function (StateMachineOne $smo, Job $job) {
            return true;
        };
        $this->getNumberTrigger = static function (StateMachineOne $smo) {
            // you could use the database if you are pleased to.
            $smo->counter++;
            return $smo->counter;
        };
        $dict = []; // we set the values as empty. The values are loaded per job basis.
        $this->serviceObject = $serviceObject;
        $this->miniLang = new $miniLangClass($this, $dict, ['wait', 'always', 'timestate'], ['timeout', 'fulltimeout'], $serviceObject);
    }


    /**
     * Add a new transition. It is the definition of transition, indicating the "from", "where" and "conditions".
     * <pre>
     * $this->addTransition(10,11,'when condition>2 set value=20','change') // if condition>2 then set the value
     *                                                                      // and change to the state 11
     * $this->addTransition(10,11,'when condition>2 set value=20','stay') // it changes values but keeps the state
     * </pre>
     *
     * @param string|int|null|array $state0     Initial state defined in setStates()
     * @param string|int|null       $state1     Ending state defined in setStates() if <b>result</b>="stay", then
     *                                          <b>state1</b> is ignored.
     * @param mixed                 $conditions It sets a condition(s) (also it could changes of properties).
     *                                          Example:<br>
     *                                          <p><b>"when store_open = 1 and stock_milk > 0"</b> = it jumps if the
     *                                          condition(s) is meet</p>
     *                                          <p><b>"when money >= price set milk = 1'"</b> = it jump if the
     *                                          condition(s) also sets milk as 1</p>
     *                                          <p><b>"when wait timeout 500"</b> = transitions if has passed more than
     *                                          500 seconds since the last stage</p>
     *                                          <p><b>"when true()"</b> = it always transitions. It is the same than
     *                                          "when
     *                                          1=1"
     *                                          </p>
     * @param string                $result     =['change','pause','continue','stop','stay'][$i]
     * @param string|null           $description
     * @return int Returns the last id of the transaction.
     * @see          \eftec\statemachineone\StateMachineOne::setStates
     * @noinspection DuplicatedCode
     */
    public function addTransition($state0, $state1, $conditions, string $result = 'change', ?string $description = null): int
    {
        if (is_array($state0)) {
            foreach ($state0 as $stateV) {
                $this->transitions[] = new Transition($this, $stateV, $state1, $conditions, $result, false, $description);
            }
        } else {
            $this->transitions[] = new Transition($this, $state0, $state1, $conditions, $result, false, $description);
        }
        return count($this->transitions) - 1;
    }

    /**
     * Add a new transition and store into Minilang, so you later could save into a Minilang Class.<br>
     * It allows to create a definition of transition, indicating the "from", "where" and "conditions".
     * <pre>
     * $this->addMethodTransition2(10,11,'when condition>2 set value=20','change') // if condition>2 then set the value
     *                                                                      // and change to the state 11
     * $this->addMethodTransition2(10,11,'when condition>2 set value=20','stay') // it changes values but keeps the
     * state
     * </pre>
     *
     * @param string|array    $state0      Initial state defined in setStates()
     * @param string|int|null $state1      Ending state defined in setStates() if <b>result</b>="stay", then
     *                                     <b>state1</b> is ignored.
     * @param mixed           $conditions  It sets a condition(s) (also it could changes of properties). Example:<br>
     *                                     <p><b>"when store_open = 1 and stock_milk > 0"</b> = it jumps if the
     *                                     condition(s) is meet</p>
     *                                     <p><b>"when money >= price set milk = 1'"</b> = it jump if the condition(s)
     *                                     also sets milk as 1</p>
     *                                     <p><b>"when wait timeout 500"</b> = transitions if has passed more than 500
     *                                     seconds since the last stage</p>
     *                                     <p><b>"when true()"</b> = it always transitions. It is the same as "when 1=1"
     *                                     </p>
     * @param string          $result      =['change','pause','continue','stop','stay'][$i]
     * @param string|null     $description An optional description
     * @return int Returns the last id of the transaction.
     * @see          \eftec\statemachineone\StateMachineOne::setStates
     * @noinspection DuplicatedCode
     */
    public function addMethodTransition2($state0, $state1, $conditions, string $result = 'change',
                                         ?string $description = null): int
    {
        if (is_array($state0)) {
            foreach ($state0 as $stateV) {
                $this->transitions[] = new Transition($this, $stateV, $state1, $conditions, $result, true, $description);
            }
        } else {
            $this->transitions[] = new Transition($this, $state0, $state1, $conditions, $result, true, $description);
        }
        return count($this->transitions) - 1;
    }

    /**
     * It is a macro of addTransition. It does an operation (indicated by "then" every time the job is in the state.<br>
     * <b>Example:</b><br>
     * <pre>
     * $this->duringState(123,'when condition>2 set value=20')
     * $this->duringState(123,'set value=20') // when is always true
     * </pre>
     *
     * @param string|array $state the id of the state.
     * @param string       $then  if then when/where condition is empty then it always true.
     * @param string|null  $description
     * @return int Returns the last id of the transaction.
     * @see          \eftec\statemachineone\StateMachineOne::setStates
     * @noinspection DuplicatedCode
     */
    public function duringState($state, string $then, ?string $description = null): int
    {
        if (stripos($then, 'when') === false || stripos($then, 'where') === false) {
            $then = 'when true() ' . $then;
        }
        if (is_array($state)) {
            foreach ($state as $stateV) {
                $this->transitions[] = new Transition($this, $stateV, $stateV, $then, 'stay', false, $description);
            }
        } else {
            $this->transitions[] = new Transition($this, $state, $state, $then, 'stay', false, $description);
        }
        return count($this->transitions) - 1;
    }

    /**
     * It is a macro of addTransition. It's similar to duringState() but it is used to store the information in
     * MiniLang, so you can save it later inside a Minilang Class
     * <b>Example:</b><br>
     * <pre>
     * $this->duringState2(123,'when condition>2 set value=20')
     * $this->duringState2(123,'set value=20') // when is always true
     * </pre>
     *
     * @param string|array $state the id of the state.
     * @param string       $then  if then when/where condition is empty then it always true.
     * @param string|null  $description
     * @return int Returns the last id of the transaction.
     * @see          \eftec\statemachineone\StateMachineOne::setStates
     * @noinspection DuplicatedCode
     */
    public function duringState2($state, string $then, ?string $description = null): int
    {
        if (stripos($then, 'when') === false || stripos($then, 'where') === false) {
            $then = 'when true() ' . $then;
        }
        if (is_array($state)) {
            foreach ($state as $stateV) {
                $this->transitions[] = new Transition($this, $stateV, $stateV, $then, 'stay', true, $description);
            }
        } else {
            $this->transitions[] = new Transition($this, $state, $state, $then, 'stay', true, $description);
        }
        return count($this->transitions) - 1;
    }

    /**
     * It removes a single transition
     *
     * @param int $idTransition
     */
    public function removeTransition(int $idTransition): void
    {
        array_splice($this->transitions, $idTransition, 1);
    }

    /**
     * It removes multiples transitions.
     *
     * @param int $transitionStart
     * @param int $length
     */
    public function removeTransitions(int $transitionStart, int $length): void
    {
        array_splice($this->transitions, $transitionStart, $length);
    }

    /**
     * It adds an event with a name
     *
     * @param int|string $name       name of the event
     * @param string     $conditions Example: 'set field = field2 , field = 0 , field = function()
     */
    public function addEvent($name, string $conditions): void
    {
        // each event is a self mini lang.
        $eventMiniLang = new MiniLang($this, $this->states, ['wait', 'always'], ['timeout', 'fulltimeout'],
            $this->serviceObject);
        $eventMiniLang->separate($conditions, -1, 'event $name');
        $this->eventNames[$name] = $conditions;
        $this->events[$name] = $eventMiniLang;
    }

    /**
     * It is used for the operation "when wait timeout 5555"
     *
     * @return int
     */
    public function wait(): int
    {
        return 0;
    }

    public function always($param = null): bool
    {
        return true;
    }

    /**
     * It returns the time elapsed in the current state of the current job.
     * @param null $param
     * @return int
     */
    public function timestate($param = null): int
    {
        return $this->getTime() - $this->getCurrentTransition()->currentJob->dateLastChange;
    }

    /**
     * We clear all transitions.
     */
    public function resetTransition(): void
    {
        $this->transitions = [];
        $this->currentTransition = -1;
        $this->debugArray = [];
    }

    /**
     * @return PdoOne
     */
    public function getPdoOne(): PdoOne
    {
        return $this->pdoOne;
    }

    /**
     * It reuses a connection to the database (if we have one, and we want to reuse it).
     *
     * @param PdoOne $pdoOne
     *
     * @see \eftec\statemachineone\StateMachineOne::setDB
     */
    public function setPdoOne(PdoOne $pdoOne): void
    {
        $this->pdoOne = $pdoOne;
        $this->dbActive = self::PDODB;
        $this->dbType = $pdoOne->databaseType;
        $this->dbSchema = $pdoOne->db;
    }

    /**
     * @return DocumentStoreOne
     */
    public function getDocOne(): DocumentStoreOne
    {
        return $this->docOne;
    }

    /**
     * It sets a DocumentStoreOne object (for re-usability)<br>
     * $docOne is marked as auto-serialize=true (using php strategy)
     *
     * @param DocumentStoreOne $docOne
     */
    public function setDocOne(DocumentStoreOne $docOne): void
    {
        $this->docOne = $docOne;
        $this->docOne->autoSerialize();
        $this->dbActive = self::DOCDB;
    }

    /**
     * DocumentStoreOne constructor.
     *
     * @param string $database      root folder of the database
     * @param string $collection    collection (subfolder) of the database. If the collection is empty then it uses the
     *                              root folder.
     * @param string $strategy      =['auto','folder','apcu','memcached','redis'][$i] The strategy is only used to
     *                              lock/unlock purposes.
     * @param string $server        Used for 'memcached' (localhost:11211) and 'redis' (localhost:6379)
     * @param string $keyEncryption =['','md5','sha1','sha256','sha512'][$i] it uses to encrypt the name of the keys
     *                              (filename)
     *
     * @throws Exception
     * @example $flatcon=new DocumentStoreOne(dirname(__FILE__)."/base",'collectionFolder');
     */
    public function setDocDB(
        string $database,
        string $collection = '',
        string $strategy = 'auto',
        string $server = '',
        string $keyEncryption = ''
    ): void
    {
        $this->dbActive = self::DOCDB;
        $this->docOne = new DocumentStoreOne($database, $collection, $strategy, $server, true, $keyEncryption);
        $this->docOne->autoSerialize();
    }

    /**
     * It sets a new connection to the database.
     *
     * @param string $type   =['mysql','sqlsrv'][$i]
     * @param string $server server ip, example "localhost"
     * @param string $user   user of the database, example "root"
     * @param string $pwd    password of the database, example "123456"
     * @param string $schema database(schema), example "sakila"
     *
     * @return bool true if the database is open
     * @see \eftec\statemachineone\StateMachineOne::setPdoOne
     *
     */
    public function setDB(string $type, string $server, string $user, string $pwd, string $schema): ?bool
    {
        $this->dbActive = self::PDODB;
        $this->dbType = $type;
        $this->dbServer = $server;
        $this->dbUser = $user;
        $this->dbPassword = $pwd;
        $this->dbSchema = $schema;
        try {
            $this->getDB();
            return true;
        } catch (Exception $e) {
            if ($this->debug) {
                if ($this->debugAsArray) {
                    $this->debugArray[] = $e->getMessage();
                } else {
                    echo($e->getMessage());
                }
            }
            return false;
        }
    }

    /**
     * It returns the current connection. If there is not a connection then it generates a new one.
     *
     * @return PdoOne
     * @throws Exception
     */
    public function getDB(): PdoOne
    {
        if ($this->pdoOne === null) {
            $this->pdoOne = new PdoOne($this->dbType, $this->dbServer, $this->dbUser, $this->dbPassword,
                $this->dbSchema);
            $this->pdoOne->open();
        }
        return $this->pdoOne;
    }

    /**
     * Loads a job from the database and adds to the queue.
     *
     * @param $idJob
     *
     * @throws Exception
     */
    public function loadDBJob($idJob): void
    {
        switch ($this->dbActive) {
            case self::PDODB:
                $row = $this->getDB()->select('*')->from($this->tableJobs)->where('idactive<>0 and idjob=?', [$idJob])
                    ->first();
                if ($row !== false) {
                    $this->jobQueue[$row['idjob']] = $this->arrayToJob($row);
                    $this->jobQueueBackup[$row['idjob']] = clone $this->jobQueue[$row['idjob']];
                }
                break;
            case self::DOCDB:
                $row = $this->docOne->get($idJob);
                if ($row !== false) {
                    $this->jobQueue[$row['idjob']] = $this->arrayToJob($row);
                    $this->jobQueueBackup[$row['idjob']] = clone $this->jobQueue[$row['idjob']];
                }
                break;
        }
    }

    /**
     * @param array $row
     *
     * @return Job
     */
    public function arrayToJob(array $row): Job
    {
        $job = new Job();
        $job->idJob = $row['idjob'];
        $job->idParentJob = $row['idparentjob'];
        $job->setIsUpdate(false)
            ->setIsNew(false)
            ->setActiveNumber($row['idactive'])
            ->setState($row['idstate'])
            ->setDateInit(strtotime($row['dateinit']))
            ->setDateLastChange(strtotime($row['datelastchange']))
            ->setDateExpired(strtotime($row['dateexpired']))
            ->setDateEnd(strtotime($row['dateend']));
        $arr = [];
        try {
            /** @noinspection UnserializeExploitsInspection */
            $text = unserialize($row['text_job']); // json_decode($row['text_job'],true);
        } catch (Exception $ex) {
            throw new RuntimeException("unable to unserialize job");
        }
        foreach ($this->fieldDefault as $k => $v) {
            if (!is_object($v)) {
                if (is_array($v)) {
                    $arr[$k] = $text[$k];
                } else {
                    $arr[$k] = $row[$k];
                }
            } elseif ($v instanceof StateSerializable) {
                //$arr[$k] = clone $v;
                $arr[$k] = $text[$k];
                $arr[$k]->setParent($job);
                $arr[$k]->setCaller($this);
                //$arr[$k]->fromString($job, $text[$k]);
            }
        }
        $job->setFields($arr);
        return $job;
    }

    /**
     * It loads all jobs from the database with all active state but none(0) and stopped(4).
     *
     * @throws Exception
     */
    public function loadDBActiveJobs(): void
    {
        switch ($this->dbActive) {
            case self::PDODB:
                $rows = $this->getDB()->select('*')->from($this->tableJobs)->where('idactive not in (0,4)')
                    ->order('dateinit')
                    ->toList();
                $this->jobQueue = [];
                $this->jobQueueBackup = [];
                foreach ($rows as $row) {
                    $this->jobQueue[$row['idjob']] = $this->arrayToJob($row);
                    $this->jobQueueBackup[$row['idjob']] = clone $this->jobQueue[$row['idjob']];
                }
                break;
            case self::DOCDB:
                $this->jobQueue = [];
                $this->jobQueueBackup = [];
                $listId = $this->docOne->select('job*');
                if ($listId) {
                    foreach ($listId as $idJob) {  // id already has json prefix
                        $id = substr($idJob, 3);
                        $job = $this->arrayToJob($this->docOne->get('job' . $id));
                        $gan = $job->getActiveNumber();
                        if ($gan !== 0 && $gan !== 4) {
                            $this->jobQueue[$id] = $job;
                            $this->jobQueueBackup[$id] = clone $job;
                        }
                    }
                }
                break;
        }
    }

    /**
     * It loads all jobs from the database regardless its active state.
     *
     * @throws Exception
     */
    public function loadDBAllJob(): void
    {
        $this->jobQueue = [];
        $this->jobQueueBackup = [];
        switch ($this->dbActive) {
            case self::PDODB:
                $rows = $this->getDB()->select('*')->from($this->tableJobs)->order('dateinit')->toList();
                foreach ($rows as $row) {
                    $this->jobQueue[$row['idjob']] = $this->arrayToJob($row);
                    $this->jobQueueBackup[$row['idjob']] = clone $this->jobQueue[$row['idjob']];
                }
                break;
            case self::DOCDB:
                $listId = $this->docOne->select('job*');
                if ($listId) {
                    foreach ($listId as $idJob) { // id already has json prefix
                        $id = substr($idJob, 3); // we remove the "job"
                        $job = $this->arrayToJob($this->docOne->get('job' . $id));
                        $this->jobQueue[$id] = $job;
                        $this->jobQueueBackup[$id] = clone $job;
                    }
                }
                break;
        }
    }

    /**
     * (optional), it creates a database table, including indexes.
     * Right now it only works with 'mysql'
     *
     * @param bool|string $drop if true, then the table will be dropped.
     *
     * @throws Exception
     */
    public function createDbTable($drop = false): void
    {
        switch ($this->dbActive) {
            case self::PDODB:
                if ($this->dbType === 'mysql') {
                    if ($drop) {
                        $sql = 'DROP TABLE IF EXISTS `' . $this->tableJobs . '`';
                        $this->getDB()->runRawQuery($sql);
                        $sql = 'DROP TABLE IF EXISTS `' . $this->tableJobLogs . '`';
                        $this->getDB()->runRawQuery($sql);
                    }
                    $exist = $this->getDB()->tableExist($this->tableJobs);
                    if ($exist === false || $drop) {
                        $tabledef = [
                            'idjob' => 'INT NOT NULL AUTO_INCREMENT',
                            'idparentjob' => 'INT',
                            'idactive' => 'int',
                            'idstate' => 'int',
                            'dateinit' => 'timestamp DEFAULT \'' . $this->zeroDate . '\'',
                            'datelastchange' => 'timestamp DEFAULT \'' . $this->zeroDate . '\'',
                            'dateexpired' => 'timestamp DEFAULT \'' . $this->zeroDate . '\'',
                            'dateend' => 'timestamp DEFAULT \'' . $this->zeroDate . '\''
                        ];
                        $this->createColsTable($tabledef, $this->fieldDefault);
                        $this->getDB()->createTable($this->tableJobs, $tabledef, 'idjob');
                        // We created index.
                        $sql = 'ALTER TABLE `' . $this->tableJobs . '`
                ADD INDEX `' . $this->tableJobs . '_key1` (`idactive` ASC),
                ADD INDEX `' . $this->tableJobs . '_key2` (`idstate` ASC),
                ADD INDEX `' . $this->tableJobs . '_key3` (`dateinit` ASC)';
                        $this->getDB()->runRawQuery($sql);
                        if ($this->tableJobLogs) {
                            $tabledef = [
                                'idjoblog' => 'INT NOT NULL AUTO_INCREMENT',
                                'idjob' => 'int',
                                'idrel' => 'varchar(200)',
                                'type' => 'varchar(50)',
                                'description' => 'varchar(2000)',
                                'date' => 'timestamp DEFAULT \'' . $this->zeroDate . '\''
                            ];
                            $this->getDB()->createTable($this->tableJobLogs, $tabledef, 'idjoblog');
                        }
                    }
                }
                break;
            case self::DOCDB:
                //$this->docOne->createCollection($this->tableJobs);
                //$this->docOne->createCollection($this->tableJobLogs);
                break;
        }
    }

    /**
     * it creates the columns of the table based in the type of fields.
     *
     * @param array $defTable
     * @param array $fields
     *
     */
    private function createColsTable(array &$defTable, array $fields): void
    {
        $defTable['text_job'] = 'MEDIUMTEXT';
        foreach ($fields as $k => $v) {
            switch (true) {
                case is_string($v):
                    $defTable[$k] = 'varchar(250)';
                    break;
                case is_float($v):
                    $defTable[$k] = 'decimal(10,2)';
                    break;
                case is_numeric($v):
                case is_bool($v):
                    $defTable[$k] = 'int';
                    break;
                case is_array($v):
                case is_object($v):
                    //$defTable['text_job'] = 'MEDIUMTEXT';
                    break;
                default: // null
                    $defTable[$k] = 'varchar(250)';
                    break;
            }
        }
    }

    /**
     * @param callable|null $function
     */
    public function setCustomSaveDbJobLog(?callable $function): void
    {
        $this->customSaveDBJobLog = $function;
    }

    /**
     * It saves all jobs in the database that are marked as new or updated.
     *
     * @return bool
     */
    public function saveDBAllJob(): bool
    {
        foreach ($this->jobQueue as $idJob => $job) {
            $backup = $this->jobQueueBackup[$idJob] ?? null;
            if ($this->saveDBJob($job, $backup) === -1) {
                return false;
            }
        }
        return true;
    }

    /**
     * It saves a job in the database. It only saves a job that is marked as new or updated
     *
     * @param Job      $job    The job to save
     * @param Job|null $backup The backup to compare. Usually, it is the previous value, and it is used to store only
     *                         columns that are changed.
     *
     * @return int Returns the id of the new job, 0 if not saved or -1 if error.
     */
    public function saveDBJob(Job $job, ?Job $backup = null): int
    {
        switch ($this->dbActive) {
            case self::PDODB:
                try {
                    if ($job->isNew) {
                        $arr = $this->jobToArray($job);
                        if (count($arr) > 0) {
                            $job->idJob = $this->getDB()
                                ->from($this->tableJobs)
                                ->set($arr)
                                ->insert();
                        }
                        $job->isNew = false;
                        //$this->jobQueue[$job->idJob]=$job;
                        return $job->idJob;
                    }
                    if ($job->isUpdate) {
                        $arr = [];
                        $newJob = $this->jobToArray($job);
                        if ($backup !== null) {
                            $arrBackup = $this->jobToArray($backup);
                            foreach ($newJob as $k => $v) {
                                if ($v !== $arrBackup[$k]) {
                                    $arr[$k] = $v;
                                }
                            }
                        } else {
                            $arr = $newJob;
                        }
                        unset($arr['idjob']); // we are not updating the index
                        if (count($arr) > 0) {
                            $this->getDB()
                                ->from($this->tableJobs)
                                ->set($arr)
                                ->where(['idjob' => $job->idJob])
                                ->update();
                        }
                        $job->isUpdate = false;
                        //$this->jobQueue[$job->idJob]=$job;
                        return $job->idJob;
                    }
                } catch (Exception $e) {
                    $this->addLog($job, 'ERROR', 'SAVEJOB', 'save,,' . $e->getMessage());
                }
                return 0;
            case self::DOCDB:
                $this->docOne->insertOrUpdate('job' . $job->idJob, $this->jobToArray($job));
                return $job->idJob;
        }
        return 0;
    }

    /**
     * It converts a job into an array
     *
     * @param Job  $job
     *
     * @param bool $serializeCustom if false, then it doesn't package text_job
     *
     * @return array
     */
    public function jobToArray(Job $job, bool $serializeCustom = true): array
    {
        $arr = [];
        $arr['idjob'] = $job->idJob;
        $arr['idparentjob'] = $job->idParentJob;
        $arr['idactive'] = $job->getActiveNumber();
        $arr['idstate'] = $job->state;
        $arr['dateinit'] = date('Y-m-d H:i:s', $job->dateInit);
        $arr['datelastchange'] = date('Y-m-d H:i:s', $job->dateLastChange);
        $arr['dateexpired'] = date('Y-m-d H:i:s', $job->dateExpired);
        $arr['dateend'] = date('Y-m-d H:i:s', $job->dateEnd);
        // native fields (fields that aren't object or array)
        if ($serializeCustom) {
            $text = [];
            foreach ($this->fieldDefault as $k => $v) {
                if (!is_object($v)) {
                    if (is_array($v)) {
                        $text[$k] = $job->fields[$k];
                    } else {
                        $arr[$k] = $job->fields[$k];
                    }
                } elseif ($v instanceof StateSerializable) {
                    /** @see \eftec\statemachineone\Flags::__serialize */
                    $text[$k] = $job->fields[$k]; //->toString();
                }
            }
            // non-native fields
            $arr['text_job'] = serialize($text);
        } else {
            foreach ($this->fieldDefault as $k => $v) {
                if (!is_object($v)) {
                    if (is_array($v)) {
                        $arr[$k] = $job->fields[$k];
                    }
                } elseif ($v instanceof StateSerializable) {
                    $arr[$k] = $job->fields[$k]; //->toString();
                }
            }
        }
        return $arr;
    }

    /**
     * It adds a log of the job.
     *
     * @param Job    $job
     * @param string $type =['ERROR','WARNING','INFO','DEBUG'][$i]
     * @param string $subtype
     * @param string $description
     * @param string $idRel
     */
    public function addLog(Job $job, string $type, string $subtype, string $description, string $idRel = ''): void
    {
        $idJob = $job->idJob;
        $arr = ['type' => $type, 'description' => $description, 'date' => $this->getTime(true), 'idrel' => $idRel];
        if (!isset($this->jobQueue[$idJob])) {
            return;
        }
        $this->jobQueue[$idJob]->log[] = $arr;
        if ($this->debug) {
            $msg = "<b>Job #$idJob</b> " . $this->dateToString($this->getTime(true)) . " [$type]:  $description<br>\n";
            if ($this->debugAsArray) {
                $this->debugArray[] = $msg;
            } else {
                echo($msg);
            }
        }
        //if ($this->dbActive !== self::NODB) {
        $arr['description'] = strip_tags($arr['description']);
        $this->saveDBJobLog($job, $arr);
        //}
    }

    /**
     * It returns the current timestamp. If exists a universal timer function (a global function called universaltime),
     * then it is uses.  Why? It is because sometimes we want the same time across different services.
     *
     * @param bool $microtime if true then it returns the microtime as float
     *
     * @return int|float
     */
    public function getTime(bool $microtime = false)
    {
        if (function_exists('universaltime')) {
            return universaltime($microtime);
        }
        return $microtime ? microtime(true) : time();
    }

    /**
     * @param mixed $time timestamp with microseconds. If "now", then it returns the current time.
     *
     * @return string
     */
    private function dateToString($time = null): string
    {
        if ($time === 'now') {
            try {
                $d = new DateTime($time);
            } catch (Exception $e) {
                $tmp = new DateTime();
                $d = $tmp->setTimestamp($this->getTime());
            }
        } else {
            // note: it failed with 7.2.17 ???
            $d = DateTime::createFromFormat('U.u', $time);
            if ($d === false) {
                $d = DateTime::createFromFormat('U', round($time));
            }
        }
        return $d->format('Y-m-d H:i:s.u');
    }

    /**
     * Insert a new job log into the database.
     *
     * @param Job   $job
     * @param array $arr
     *
     * @return bool|null
     * @see \eftec\statemachineone\StateMachineOne::$customSaveDBJobLog
     */
    public function saveDBJobLog(Job $job, array $arr): ?bool
    {
        if (is_callable([$this, 'customSaveDBJobLog'], true) && $this->customSaveDBJobLog !== null) {
            return call_user_func($this->customSaveDBJobLog, $job, $arr);
            //$this->customSaveDBJobLog($job,$arr);
        }
        switch ($this->dbActive) {
            case self::PDODB:
                if (!$this->tableJobLogs) {
                    return true;
                } // it doesn't save if the table is not set.
                try {
                    $query = $this->getDB()
                        ->from($this->tableJobLogs);
                    $query->set('idjob=?', $job->idJob);
                    $query->set('idrel=?', $arr['idrel']);
                    $query->set('type=?', $arr['type']);
                    $query->set('description=?', $arr['description']);
                    $query->set('date=?', date('Y-m-d H:i:s', $arr['date']));
                    $query->insert();
                    return true;
                } catch (Exception $e) {
                    echo 'error ' . $e->getMessage();
                    return false;
                    //$this->addLog(0,"ERROR","Saving the joblog ".$e->getMessage());
                }
            case self::DOCDB:
                // it stores the log as csv
                $log = [
                    'idjob' => $job->idJob,
                    'idrel' => $arr['idrel'],
                    'type' => $arr['type']
                    ,
                    'description' => $arr['description'],
                    'date' => date('Y-m-d H:i:s', $arr['date'])
                ];
                $this->docOne->appendValue($this->tableJobLogs, $this->csvStr($log));
                break;
        }
        return false;
    }

    /**
     * It converts a simple array (not nested) into a csv.
     *
     * @param array $arrayValue
     *
     * @return bool|string
     */
    private function csvStr(array $arrayValue)
    {
        /** @noinspection FopenBinaryUnsafeUsageInspection */
        $f = fopen('php://memory', 'r+');
        if (fputcsv($f, $arrayValue) === false) {
            @fclose($f);
            return false;
        }
        rewind($f);
        $csv_line = stream_get_contents($f);
        @fclose($f);
        return $csv_line;
    }

    /**
     * It checks all jobs available (if the active state of the job is any but none or stop)
     *
     * @param int $numIteractions the numbers of time to check the transition.
     *
     * @return bool true if the operation was successful, false if error.
     */
    public function checkAllJobs(int $numIteractions = 3): bool
    {
        $this->changed = false;
        foreach ($this->jobQueue as $idx => $job) {
            if ($job instanceof Job) { // why?, because we use foreach
                for ($iteraction = 0; $iteraction < $numIteractions; $iteraction++) {
                    $ga = $job->getActive();
                    if ($ga !== 'none' && $ga !== 'stop') {
                        try {
                            $this->checkJob($job);
                        } catch (Exception $e) {
                            $txt = isset($this->transitions[$this->currentTransition])
                                ? $this->transitions[$this->currentTransition]->txtCondition
                                : null;
                            $this->addLog($job, 'ERROR'
                                , 'CHECK', "state,,transition,,$txt,,$this->currentTransition,," . $e->getMessage());
                            return false;
                        }
                    }
                }
                $backup = $this->jobQueueBackup[$idx] ?? null;
                $this->saveDBJob($job, $backup);
            }
            /*if (!$this->changed) {
                break;
            }*/
        }
        return true;
    }

    /**
     * It checks a specific job and proceed to change state.
     * We check a job and we change the state
     *
     * @param Job $job
     *
     * @throws Exception
     */
    public function checkJob(Job $job): void
    {
        if ($job->dateInit <= $this->getTime() && $job->getActive() === 'inactive') {
            // it starts the job.
            $this->callStartTrigger($job);
            $job->setActive();
            $job->setIsUpdate();
        }
        foreach ($this->transitions as $idTransition => $trn) {
            $this->currentTransition = $idTransition;
            // the isset it is because the job could be deleted from the queue.
            // if the state of the job is equals than the transition
            if (isset($job) && $trn->state0 == $job->state) {
                if ($this->getTime() - $job->dateLastChange >= $trn->getDuration($job)
                    || $this->getTime() - $job->dateInit >= $trn->getFullDuration($job)
                ) {
                    // timeout time is up, we will do the transition anyway
                    $this->miniLang->setDict($job->fields);
                    $this->miniLang->setDictEntry('_idjob', $job->idJob);
                    $this->miniLang->setDictEntry('_time', $this->getTime());
                    $this->miniLang->setDictEntry('_state0', $job->state);
                    if ($trn->doTransition($this, $job, true, $idTransition)) {
                        if ($trn->state0 != $trn->state1) {
                            $job->stateFlow[] = [$trn->state0, $trn->state1];
                        }
                        $this->changed = true;
                    }
                } elseif ($this->miniLang->usingClass || count($this->miniLang->where[$idTransition])) {
                    // we check the transition based on table
                    $this->miniLang->setDict($job->fields);
                    $this->miniLang->setDictEntry('_idjob', $job->idJob);
                    $this->miniLang->setDictEntry('_time', $this->getTime());
                    $this->miniLang->setDictEntry('_state0', $job->state);
                    if ($trn->evalLogic($this, $job, $idTransition)) {
                        if ($trn->result !== 'stay') {
                            $job->stateFlow[$idTransition] = [$trn->state0, $trn->state1];
                        }
                        $this->changed = true;
                    }
                } elseif (is_callable($trn->function)) {
                    // we check the transition based on function
                    if (call_user_func($trn->function, $this, $job)) {
                        if ($trn->result !== 'stay') {
                            $job->stateFlow[$idTransition] = [$trn->state0, $trn->state1];
                        }
                        $this->changed = true;
                    }
                }
            }
        }
    }

    public function callStartTrigger($job)
    {
        return call_user_func($this->startTrigger, $this, $job);
    }

    /**
     * Delete the none/stop jobs of the queue.
     */
    public function garbageCollector(): void
    {
        foreach ($this->jobQueue as $job) {
            if ($job instanceof Job) {
                $ga = $job->getActive();
                if ($ga === 'none' || $ga === 'stop') {
                    $this->removeJob($job);
                }
            }
        }
    }

    /**
     * It removes a jobs of the queue.
     *
     * @param Job|null $job
     *
     * @test void removeJob(null)
     */
    public function removeJob(?Job &$job): void
    {
        if ($job === null) {
            return;
        }
        $id = $job->idJob;
        $job = null;
        $this->jobQueue[$id] = null;
        unset($this->jobQueue[$id]);
    }

    public function cacheMachine($fnName = 'myMachine'): string
    {
        return <<<cin
/**
 * @param \eftec\statemachineone\StateMachineOne \$machine
 */
function $fnName(\$machine) {
    // transitions
    {$this->cacheTransitions()}
    foreach(\$machine->transitions as &\$trans) {
        \$trans->caller=\$machine;
    }
    // states
    {$this->cacheStates()}
    
    // events [optional] such as "click", "close_operation", etc.
    {$this->cacheEvents()}
    foreach(\$machine->events as &\$event) {
        \$event->setCaller(\$machine);
    }
    
    // minilang
    {$this->cacheMiniLang()}
    \$machine->miniLang->serviceClass=\$machine->serviceObject;
    \$machine->miniLang->setCaller(\$machine);    
}
cin;
    }

    private function cacheTransitions(): string
    {
        if (count($this->transitions) == 0) {
            return '';
        }
        $transitions = $this->transitions;
        // we removed the caller to avoid circular reference.
        foreach ($transitions as $trans) {
            $trans->caller = null;
        }
        $phpCode = '$machine->transitions=unserialize( \'' . $this->serializeEscape($transitions) . '\');';
        //$phpCode=str_replace("  ","\t",$phpCode);
        foreach ($transitions as $trans) {
            $trans->caller = $this;
        }
        return $phpCode;
    }

    private function serializeEscape($object)
    {
        //return serialize($object);
        return str_replace('\'', "\\'", serialize($object));
    }

    private function cacheStates(): string
    {
        if (count($this->states) === 0) {
            return '';
        }
        $states = $this->states;
        return '$machine->states=unserialize( \'' . $this->serializeEscape($states) . '\');';
    }

    private function cacheEvents(): string
    {
        if (count($this->events) === 0) {
            return '';
        }
        $events = $this->events;
        // we removed the caller to avoid circular reference.
        foreach ($events as $event) {
            $event->setCaller(null);
        }
        $phpCode = '$machine->events=unserialize( \'' . $this->serializeEscape($events) . '\');';
        $phpCode .= "\n  \$machine->eventNames=unserialize( '" . $this->serializeEscape($this->eventNames) . '\');';
        //$phpCode=str_replace("  ","\t",$phpCode);
        foreach ($events as $event) {
            $event->setCaller($this);
        }
        return $phpCode;
    }

    //<editor-fold desc="Cache">
    private function cacheMiniLang(): string
    {
        //$phpCode=str_replace("  ","\t",$phpCode);
        return '$machine->miniLang=unserialize( \'' . str_replace('\'', "\\'", $this->miniLang->serialize())
            . '\');';
    }

    /**
     * It fetches the UI (it reads the user input values).<br>
     *
     * @return string Returns an information message, for example "Job create".
     * @throws Exception
     */
    public function fetchUI(): string
    {
        // fetch values
        $lastjob = $_REQUEST['frm_curjob'] ?? null;
        if (!$lastjob) {
            $job = $this->getLastJob();
        } else {
            $lastjob = (int)$lastjob;
            $job = $this->getJob($lastjob);
            if (!$job) {
                $job = $this->getLastJob();
            }
        }
        $jobBackup = $job === null ? null : clone $job;
        $button = $_REQUEST['frm_button'] ?? null;
        $buttonEvent = $_REQUEST['frm_button_event'] ?? null;
        $new_state = $_REQUEST['frm_new_state'] ?? null;
        $msg = '';
        $fetchField = $this->fieldDefault;
        foreach ($this->fieldDefault as $colFields => $value) {
            $fieldName = 'frm_' . $colFields;
            if (isset($_REQUEST[$fieldName])) {
                if ($value instanceof StateSerializable) {
                    $fetchField[$colFields] = clone $value;
                    $fetchField[$colFields]->fromString(
                        $job
                        , $_REQUEST['frm_' . $colFields] ?? null);
                } else {
                    $fetchField[$colFields] = $_REQUEST['frm_' . $colFields] ?? null;
                    if (is_array($value)) {
                        $fetchField[$colFields] = ($fetchField[$colFields] === '') ? null
                            : json_decode($fetchField[$colFields]);
                    } else {
                        $fetchField[$colFields] = ($fetchField[$colFields] === '') ? null : $fetchField[$colFields];
                    }
                }
            }
        }
        if ($buttonEvent) {
            $this->callEvent($buttonEvent, $job);
            if ($job !== null) {
                $msg = "Event $buttonEvent called";
                $job->isUpdate = true;
                $this->saveDBJob($job, $jobBackup);
            } else {
                $msg = 'Job not created';
            }
            $fetchField = null;
        }
        switch ($button) {
            case 'create':
                $this->createJob($fetchField);
                $msg = 'Job created with the information on screen';
                break;
            case 'createnew':
                $this->createJob($this->fieldDefault);
                $msg = 'Job created with the default information';
                break;
            case 'delete':
                if ($job != null) {
                    $job->setActive('none');
                    $job->isUpdate = true;
                    //$this->saveDBJob($job);
                    try {
                        $this->deleteJobDB($job);
                        $msg = 'Job deleted';
                    } catch (Exception $e) {
                        $msg = 'Error deleting the job ' . $e->getMessage();
                    }
                    $this->removeJob($job);
                }
                break;
            case 'change':
                $this->changeState($job, $new_state);
                $ga = $job->getActive();
                if ($ga === 'none' || $ga === 'stop') {
                    $job->setActive($ga); // we change the state to active.
                }
                $this->saveDBJob($job, $jobBackup);
                $msg = 'State changed';
                break;
            case 'setfield':
                if ($job !== null) {
                    $job->fields = $fetchField;
                    $job->isUpdate = true;
                    $this->saveDBJob($job, $jobBackup);
                    $msg = 'Job updated';
                }
                break;
            case 'check':
                echo "<div style='overflow:auto; width: 98%; height:400px; overflow-x: scroll;'>";
                $this->checkConsistency();
                echo "</div>";
                break;
        }
        return $msg;
    }

    /**
     * @return Job|mixed|null
     */
    public function getLastJob()
    {
        if (count($this->jobQueue) === 0) {
            return null;
        }
        return end($this->jobQueue);
    }

    /*private function serializeSplit($txt,$tabs="\t\t") {
        $size=strlen($txt);
        $pack=ceil($size/80);
    }*/
    /**
     * It gets a job by id.
     *
     * @param int $idJob
     *
     * @return Job|null returns null if the job doesn't exist.
     */
    public function getJob(int $idJob): ?Job
    {
        return $this->jobQueue[$idJob] ?? null;
    }

    /**
     * It calls an event previously defined by addEvent()
     *
     * @param          $name
     * @param Job|null $job
     *
     * @throws Exception
     * @see \eftec\statemachineone\StateMachineOne::addEvent
     */
    public function callEvent($name, ?Job $job = null): void
    {
        if (!isset($this->events[$name])) {
            trigger_error('event [$name] not defined');
        }
        $jobExec = $job ?? $this->getLastJob();
        $jobBackup = $job === null ? null : clone $jobExec;
        if ($jobExec === null) {
            return;
        }
        $this->events[$name]->setDict($jobExec->fields);
        $this->events[$name]->setDictEntry('_idjob', $job->idJob);
        $this->events[$name]->setDictEntry('_time', $this->getTime());
        $this->events[$name]->setDictEntry('_state0', $job->state);
        $this->events[$name]->evalSet();
        $this->checkJob($jobExec);
        if ($this->dbActive != self::NODB) {
            $this->saveDBJob($jobExec, $jobBackup);
        }
    }

    /**
     * It creates a new job.
     *
     * @param array|null $fields      The fields of the new job. If null, then it uses the default values defined by
     *                                $this->fieldDefault
     * @param string     $active      =['none','inactive','active','pause','stop'][$i]
     * @param mixed      $initState
     * @param int|null   $dateStart
     * @param int|null   $durationSec Duration (maximum) in seconds of the event
     * @param int|null   $expireSec
     *
     * @return Job
     */
    public function createJob(
        ?array $fields = null,
        string $active = 'active',
               $initState = null,
        ?int   $dateStart = null,
        ?int   $durationSec = null,
        ?int   $expireSec = null
    ): Job
    {
        $fields = $fields ?? $this->fieldDefault;
        $initState = $initState ?? $this->defaultInitState;
        $dateStart = $dateStart ?? (int)$this->getTime();
        $dateEnd = $durationSec === null ? 2047483640 : $dateStart + $durationSec;
        $dateExpire = $expireSec === null ? 2047483640 : $dateStart + $expireSec;
        $job = new Job();
        $job->setDateInit($dateStart)
            ->setDateLastChange($this->getTime()) // now.
            ->setDateEnd($dateEnd)
            ->setDateExpired($dateExpire)
            ->setState($initState)
            ->setFields($fields)
            ->setActive($active)
            ->setIsNew()
            ->setIsUpdate(false);
        switch ($this->dbActive) {
            case self::PDODB:
                $this->saveDBJob($job);
                break;
            case self::DOCDB:
                $idJob = $this->docOne->getNextSequence('seq_' . $this->tableJobs);
                $job->idJob = $idJob;
                break;
            default:
                $idJob = call_user_func($this->getNumberTrigger, $this);
                $job->idJob = $idJob;
        }
        if ($active === 'active' || $dateStart <= $this->getTime()) {
            // it starts.
            $this->callStartTrigger($job);
            $job->setActive($active);
            if ($this->dbActive !== self::NODB) {
                $this->saveDBJob($job);
            }
        }
        $this->jobQueue[$job->idJob] = $job; // we store the job created in the list of jobs
        $this->jobQueueBackup[$job->idJob] = clone $job;
        return $job;
    }
    //</editor-fold>

    /**
     * @param Job $job
     *
     * @throws Exception
     */
    public function deleteJobDB(Job $job): void
    {
        switch ($this->dbActive) {
            case self::PDODB:
                $this->getDB()
                    ->from($this->tableJobs)
                    ->where(['idjob' => $job->idJob])
                    ->delete();
                break;
            case self::DOCDB:
                $this->docOne->delete('job' . $job->idJob);
                break;
        }
    }


    //<editor-fold desc="UI">

    /**
     * It changes the state of a job manually.
     * It changes the state manually.
     *
     * @param Job             $job
     * @param string|int|null $newState
     *
     * @return bool true if the operation was succesful, otherwise (error) it returns false
     */
    public function changeState(Job $job, $newState): bool
    {
        if ($this->callChangeStateTrigger($job, $newState)) {
            $job->state = $newState;
            $job->isUpdate = true;
            $job->dateLastChange = $this->getTime();
            return true;
        }
        $this->addLog($job, 'ERROR', 'CHANGESTATE', "change,,$job->idJob,,$job->state,,$newState");
        return false;
    }

    /**
     * @param Job             $job
     * @param string|int|null $newState
     * @return mixed
     */
    public function callChangeStateTrigger(Job $job, $newState)
    {
        return call_user_func($this->changeStateTrigger, $this, $job, $newState);
    }

    /**
     * We check if the states are consistency. It is only for testing.
     *
     * @test void this()
     *
     * @param bool $output if true then it echo the result
     *
     * @return bool
     */
    public function checkConsistency(bool $output = true): bool
    {
        $arr = array_keys($this->states);
        $arrCopy = $arr;
        if ($output) {
            echo '<hr>checking:<hr>';
        }
        $result = true;
        $anterior = '';
        $html = [];
        // sort
        $transitions = $this->transitions;
        $num = count($transitions);
        //foreach ($transitions as $i => $iValue) {
        /** @noinspection ForeachInvariantsInspection */
        for ($i = 0; $i < $num; $i++) {
            for ($e = $i + 1; $e < $num; $e++) {
                if ($transitions[$e]->state0 < $transitions[$i]->state0) {
                    $tmp = $transitions[$e];
                    $transitions[$e] = $transitions[$i];
                    $transitions[$i] = $tmp;
                }
            }
        }
        foreach ($transitions as $trans) {
            $name0 = $this->states[$trans->state0];
            $name1 = $this->states[$trans->state1];
            $condition = $trans->getTxtCondition();
            $condition = str_replace("\n", " ", $condition);
            $init = self::between($condition, 'init ', ' when ');
            $where0 = self::between($condition, 'when ', ' then ');
            $where0 = $where0 === false ? self::between($condition, 'when ', ' set ') : $where0;
            $where0 = $where0 === false ? self::between($condition, 'when ', ' else ') : $where0;
            $where0 = $where0 === false ? self::between($condition, 'when ', '') : $where0;
            $set = self::between($condition, ' then ', ' else ');
            $set = $set === false ? self::between($condition, ' set ', ' else ') : $set;
            $set = $set === false ? self::between($condition, ' then ', '') : $set;
            $set = $set === false ? self::between($condition, ' set ', '') : $set;
            $else = self::between($condition, ' else ', '');
            $else = $else === false ? self::between($condition, 'else ', '') : $else;
            if ($output) {
                if ($trans->state0 !== $anterior) {
                    $anterior = $trans->state0;
                    $html = ["STEP: <b>$name0($trans->state0)</b> {xx}<br>"];
                }
                switch ($trans->result) {
                    case 'change':
                        $icon = '➡';
                        break;
                    case 'pause':
                        $icon = '⏸';
                        break;
                    case 'continue':
                        $icon = '▶';
                        break;
                    case 'stop':
                        $icon = '⏹';
                        break;
                    case 'stay':
                        $icon = '🔁';
                        break;
                    default:
                        $icon = '🔂';
                }
                if ($init) {
                    $html[] = str_repeat('&nbsp;', 4) . "❗ init:" . htmlentities($init) . "<br>";
                }
                if ($trans->result === 'change') {
                    $html[] = str_repeat('&nbsp;', 4) . "❓ " . htmlentities($where0 ?? '(no condition)') . ' (' . $trans->result . ") $icon <b>$name1 ($trans->state1)</b> <i> $trans->description</i><br>";
                } else {
                    $html[] = str_repeat('&nbsp;', 4) . "❓ " . htmlentities($where0 ?? '(no condition)') . ' (' . $trans->result . ") $icon <i> $trans->description</i><br>";
                }
                if ($set) {
                    $html[] = str_repeat('&nbsp;', 10) . "✔ " . htmlentities($set) . " <br>";
                }
                if ($else) {
                    $html[] = str_repeat('&nbsp;', 10) . "❌ " . htmlentities($else) . " <br>";
                }
            }
            $fail = false;
            if (!in_array($trans->state0, $arr)) {
                $fail = true;
                $result = false;
                if ($output) {
                    $html[0] = str_replace('{xx}', "ERROR: Transition <b>$name0</b> -> <b>$name1</b> with missing initial state<br>", $html[0] ?? '{xx}');
                }
            } else {
                $arrCopy[] = $trans->state0;
            }
            if (!in_array($trans->state1, $arr)) {
                $fail = true;
                $result = false;
                if ($output) {
                    $html[0] = str_replace('{xx}', "ERROR: Transition <b>$name0</b> -> <b>$name1</b> with missing ending state<br>", $html[0] ?? '{xx}');
                }
            } else {
                $arrCopy[] = $trans->state1;
            }
            if (!$fail && $output) {
                $html[0] = str_replace('{xx}', "🆗", $html[0] ?? '{xx}');
            }
            echo implode("\n", $html);
            $html = [];
        }
        foreach ($arr as $missing) {
            if (!in_array($missing, $arrCopy)) {
                $result = false;
                if ($output) {
                    echo "State: $missing not used<br>";
                }
            }
        }
        return $result;
    }

    public function viewJson($job = null, $msg = ''): void
    {
        $job = $job ?? $this->getLastJob();
        header('Content-Type: application/json');
        echo json_encode($job);
    }



    //</editor-fold>
    //<editor-fold desc="setter and getters">
    /**
     * View UI (for testing). It is based on ChopSuey.
     *
     * @param Job|null $job
     * @param string   $msg
     */
    public function viewUI(?Job $job = null, string $msg = ''): void
    {
        $lastjob = '';
        if (($job === null)) {
            $lastjob = $_REQUEST['frm_curjob'] ?? null;
            if (!$lastjob) {
                $job = $this->getLastJob();
            } else {
                $lastjob = (int)$lastjob;
                $job = $this->getJob($lastjob); // we read the job by id
                if (!$job) {
                    $job
                        = $this->getLastJob(); // if we are unable to read the job (it was deleted), then we read the last
                }
            }
        }
        $idJob = ($job === null) ? '??' : $job->idJob;
        $jobCombobox = "<select name='frm_curjob' class='form-control'>\n";
        $jobCombobox .= "<option value='$idJob'>--Last Job ($idJob)--</option>\n";
        $queue = $this->getJobQueue();
        foreach ($queue as $tmpJ) {
            $jobCombobox .= "<option value=$tmpJ->idJob " . ($lastjob == $tmpJ->idJob ? 'selected' : '')
                . " >$tmpJ->idJob</option>\n";
        }
        $jobCombobox .= '</select>';
        echo '<!doctype html>';
        echo "<html lang='en'>";
        echo '<head><title>StateMachineOne Version ' . $this->VERSION . '</title>';
        echo "<meta charset='utf-8'><meta name='viewport' content='width=device-width, initial-scale=1, shrink-to-fit=no'>";
        echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.3/dist/css/bootstrap.min.css" integrity="sha384-TX8t27EcRE3e/ihU7zmQxVncDAy5uIKz4rEkgIXeMed4M0jlfIDPvg6uqKI2xXr2" crossorigin="anonymous">';
        echo '<style>html { font-size: 14px; }</style>';
        echo '</head><body>';
        echo "<div class='container-fluid'><div class='row'><div class='col'><br>";
        echo '<div class="card">';
        echo "<form method='post'>";
        echo '<h5 class="card-header bg-primary text-white">';
        echo 'StateMachineOne Version ' . $this->VERSION . ' Job #' . $idJob . ' Jobs in queue: ' .
            ' (' . count($this->getJobQueue()) . ') </h5>';
        echo '<div class="card-body">';
        if ($msg != '') {
            echo '<div class="alert alert-primary" role="alert">' . $msg . '</div>';
        }
        if ($job === null) {
            echo '<h2>There is not a job active</h2><br>';
            $job = new Job();
            $job->fields = $this->fieldDefault;
        }
        echo "<div class='row'><div class='col-6'><!-- primera seccion -->";
        echo "<div class='form-group row'>";
        echo "<label class='col-sm-4 col-form-label'>Job #</label>";
        echo "<div class='col-sm-5'><span>$jobCombobox</span></br>";
        echo '</div></div>';
        echo "<div class='form-group row'>";
        echo "<label class='col-sm-4 col-form-label'>Current State</label>";
        echo "<div class='col-sm-5'><span class='badge badge-primary'>" . @$this->getStates()[$job->state] . ' ('
            . $job->state . ')</span></br>';
        echo '</div></div>';
        $tr = [];
        foreach ($this->transitions as $tran) {
            if ($tran->state0 == $job->state && $tran->result !== 'stay') {
                $tr[] = "<span class='badge badge-primary' title='$tran->txtCondition'>"
                    . @$this->getStates()[$tran->state1] . ' (' . $tran->state1 . ')</span>';
            }
        }
        echo "<div class='form-group row'>";
        echo "<label class='col-sm-4 col-form-label'>Possible next states</label>";
        echo "<div class='col-sm-5'><span >" . implode(', ', $tr) . '</span></br>';
        echo '</div></div>';
        echo "<div class='form-group row'>";
        echo "<label class='col-sm-4 col-form-label'>Current Active state</label>";
        echo "<div class='col-sm-5'><span class='badge badge-primary'>" . $job->getActive() . ' ('
            . $job->getActiveNumber() . ')' . '</span></br>';
        echo '</div></div>';
        echo "<div class='form-group row'>";
        echo "<label class='col-sm-4 col-form-label'>Elapsed full (sec)</label>";
        $delta = ($this->getTime() - $job->dateInit);
        echo "<div class='col-sm-5'><span>" . gmdate('H:i:s', $delta) . " ($delta seconds)" . '</span></br>';
        echo '</div></div>';
        echo "<div class='form-group row'>";
        echo "<label class='col-sm-4 col-form-label'>Elapsed last state (sec)</label>";
        $delta = ($this->getTime() - $job->dateLastChange);
        echo "<div class='col-sm-5'><span>" . gmdate('H:i:s', $delta) . " ($delta seconds)"
            . '</span></br>';
        echo '</div></div>';
        echo '<!-- fin primera seccion --></div>';
        echo "<div class='col-6'><!-- segunda seccion -->";
        if ($this->debugAsArray) {
            echo "<div class='form-group row'>";
            $log = implode('', $this->debugArray);
            echo "<div class='col-sm-12'>$log</div>";
            echo '</div>';
        }
        echo '<!-- fin segunda seccion --></div></div>';
        echo "<div class='form-group row'>";
        echo "<label class='col-sm-2 col-form-label'>Change State</label>";
        echo "<div class='col-sm-8'><select class='form-control' name='frm_new_state'>";
        foreach ($this->states as $k => $s) {
            if ($job->state == $k) {
                echo "<option value='$k' selected>$s</option>\n";
            } else {
                echo "<option value='$k'>$s</option>\n";
            }
        }
        echo '</select></div>';
        echo "<div class='col-sm-2'><button class='btn btn-success' name='frm_button' type='submit' value='change'>Change State</button></div>";
        echo '</div>';
        echo "<div class='form-group'>";
        echo "<button class='btn btn-primary' name='frm_button' type='submit' title='Refresh the current screen' value='refresh'>Refresh</button>&nbsp;&nbsp;&nbsp;";
        echo "<button class='btn btn-primary' name='frm_button' type='submit' title='It sets the job using the current fields' value='setfield'>Set field values</button>&nbsp;&nbsp;&nbsp;";
        echo "<button class='btn btn-success' name='frm_button' type='submit' title='Create a new job using the information in the current screen' value='create'>Create a new Job (current data) </button>&nbsp;&nbsp;&nbsp;";
        echo "<button class='btn btn-success' name='frm_button' type='submit' title='Create a new job using the default information'  value='createnew'>Create a new Job (default data)</button>&nbsp;&nbsp;&nbsp;";
        echo "<button class='btn btn-warning' name='frm_button' type='submit' value='check'>Generate diagram</button>&nbsp;&nbsp;&nbsp;";
        echo "<button class='btn btn-danger' name='frm_button' type='submit' value='delete'>Delete this job</button>&nbsp;&nbsp;&nbsp;";
        echo '</div>';
        echo "<div class='form-group row'>";
        echo "<label class='col-sm-2 col-form-label'>Events</label>";
        echo "<div class='col-sm-10'><span>";
        foreach ($this->events as $k => $v) {
            echo "<button class='btn btn-primary' name='frm_button_event' type='submit' value='$k' title='"
                . $this->eventNames[$k] . "' >$k</button>&nbsp;&nbsp;&nbsp;";
        }
        echo '</span></br>';
        echo '</div></div>';
        echo "<div class='row'>";
        foreach ($this->fieldDefault as $colFields => $value) {
            //echo "<div class='form-group'>";
            echo "<label class='col-sm-2 col-form-label'>$colFields</label>";
            echo "<div class='col-md-4'>";
            if ($value instanceof StateSerializable) {
                if ($value instanceof Flags) {
                    echo "<input type='hidden' name='frm_$colFields' value='"
                        . htmlentities($job->fields[$colFields]->toString()) . "' />";
                    $level = $job->fields[$colFields]->getMinLevel();
                    $css = ($level == 0) ? 'alert-primary' : (($level == 1) ? 'alert-warning' : 'alert-danger');
                    /** @see \eftec\statemachineone\Flags::getStack() */
                    $stack = $job->fields[$colFields]->getStack();
                    echo "<div class='alert $css'>";
                    foreach ($stack as $item) {
                        echo htmlentities($item) . '<br>';
                    }
                    echo '</div>';
                } else {
                    $type = $this->fieldUI[$colFields] ?? 'READWRITE';
                    $this->viewUIField($type, $colFields, $job->fields[$colFields]->toString());
                }
            } elseif (is_array($value)) {
                echo "<input class='form-control' autocomplete='off' 
                type='text' name='frm_$colFields' 
                value='" . htmlentities(json_encode($job->fields[$colFields])) . "' /></br>";
            } else {
                $type = $this->fieldUI[$colFields] ?? 'READWRITE';
                $this->viewUIField($type, $colFields, $job->fields[$colFields]);
            }
            echo '</div>';
            //echo "</div>";
        }
        echo '</div>'; //row
        if (count($job->stateFlow)) {
            echo "<div class='form-group row'>";
            echo "<label class='col-sm-2 col-form-label'>Transitions</label>";
            echo "<div class='col-sm-10'>";
            foreach ($job->stateFlow as $trans) {
                $tr0 = $this->states[$trans[0]] . " ($trans[0]) ";
                $tr1 = $this->states[$trans[1]] . " ($trans[1]) ";
                echo "$tr0 -&gt; $tr1<br/>";
            }
            echo '</div>';
            echo '</div>';
        }
        echo '</div>';
        echo '</form>';
        echo '</div></div>'; //card
        echo '</div><!-- col --></div><!-- row --><br>';
        echo '<script src="https://code.jquery.com/jquery-3.6.1.min.js" integrity="sha256-o88AwQnZB+VDvE9tvIXrMQaPlFFSUTR+nldQm1LuPXQ=" crossorigin="anonymous"></script>';
        echo '<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-ho+j7jyWK8fNQe+A12Hb8AhRq26LrZ/JpcUGGOn+Y7RsweNrtN/tE3MoK7ZeZDyx" crossorigin="anonymous"></script>';
        echo '</body></html>';
    }

    /**
     * Returns the job queue.  It returns the array as values but each job is a reference.
     *
     * @return Job[]
     */
    public function getJobQueue(): array
    {
        return $this->jobQueue;
    }

    /**
     * Obtain a string between one text and other.
     * Example: between('mary has a lamb','has','lamb') // returns ' a '
     *
     * @param string   $haystack
     * @param string   $startNeedle The initial text to search<br />
     *                              if empty then it starts at the start of the haystack.
     * @param string   $endNeedle   The end tag to search<br />
     *                              if empty then it ends at the end of the haystack
     * @param null|int $offset
     * @param bool     $ignoreCase
     *
     * @return bool|string
     */
    public static function between(string $haystack, string $startNeedle, string $endNeedle, int &$offset = 0,
                                   bool   $ignoreCase = false)
    {
        if ($startNeedle === '') {
            $ini = 0;
        } else {
            $ini = ($ignoreCase) ? @stripos($haystack, $startNeedle, $offset)
                : @strpos($haystack, $startNeedle, $offset);
        }
        if ($ini === false) {
            return false;
        }
        $ini += strlen($startNeedle);
        if ($endNeedle === '') {
            $len = strlen($haystack);
        } else {
            $len = (($ignoreCase) ? stripos($haystack, $endNeedle, $ini) : strpos($haystack, $endNeedle, $ini));
            if ($len === false) {
                return false;
            }
            $len -= $ini;
        }
        $offset = $ini + $len;
        return substr($haystack, $ini, $len);
    }

    /**
     * Set the job queue
     *
     * @param Job[] $jobQueue
     */
    public function setJobQueue(array $jobQueue): void
    {
        $this->jobQueue = $jobQueue;
    }

    /**
     * Gets an array with the states
     *
     * @return array
     */
    public function getStates(): array
    {
        return $this->states;
    }

    /**
     * Set the array with the states.
     *
     * @param array     $states     It could be an associative array (1=>'state name',2=>'state') or a numeric array
     *                              (1,2)
     * @param null|bool $generateId if false then it self-generates the id (based in the data), if true then it is
     *                              calculated
     */
    public function setStates(array $states, ?bool $generateId = true): void
    {
        if (!$generateId) {
            $this->states = $states;
        } elseif ($this->isAssoc($states)) {
            $this->states = $states;
        } else {
            // it converts into an associative array
            $this->states = array_combine($states, $states);
        }
    }

    private function viewUIField($type, $colFields, $value): void
    {
        if (is_array($type)) {
            if (count($type) === 2) {
                echo "<div class='input-group mb-3'>
                          <div class='input-group-prepend' id='button-addon3'>";
                foreach ($type as $k => $v) {
                    echo "<button class='btn btn-outline-secondary' type='button' 
                            onclick=\"document.getElementById('frm_$colFields').value= '$v'\">$k</button>";
                }
                echo "</div>
                          <input type='text' class='form-control' name='frm_$colFields' id='frm_$colFields'
                          value='" . htmlentities($value) . "'>
                        </div>";
            } else {
                echo "<div class='input-group mb-3'>
              <div class='input-group-prepend'>
                <button class='btn btn-outline-secondary dropdown-toggle' type='button' data-toggle='dropdown' aria-haspopup='true' aria-expanded='false'>Dropdown</button>
                <div class='dropdown-menu'>";
                foreach ($type as $k => $v) {
                    echo "<a class='dropdown-item' href='#' onclick=\"document.getElementById('frm_$colFields').value='$v'; return false;\">$k</a>";
                }
                echo "    </div>
              </div>
              <input type='text' class='form-control' name='frm_$colFields' id='frm_$colFields' value='" . htmlentities($value) . "'>
            </div>";
            }
            return;
        }
        switch ($type) {
            case 'READ':
                echo "<input class='form-control' autocomplete='off' readonly
                                type='text' name='frm_$colFields' 
                                value='" . htmlentities($value ?? '') . "' /></br>";
                break;
            case 'ONOFF':
                echo "<div class='input-group mb-3'>
                                  <div class='input-group-prepend' id='button-addon3'>
                                    <button class='btn btn-outline-secondary' type='button' onclick=\"document.getElementById('frm_$colFields').value=1\">ON</button>
                                    <button class='btn btn-outline-secondary ' type='button' onclick=\"document.getElementById('frm_$colFields').value=0\">OFF</button>
                                  </div>
                                  <input type='text' class='form-control' name='frm_$colFields' id='frm_$colFields'
                                  value='" . htmlentities($value) . "'>
                                </div>";
                break;
            case 'NUMERIC':
                echo "<input class='form-control' autocomplete='off' 
                                type='numeric' name='frm_$colFields' 
                                value='" . htmlentities($value) . "' /></br>";
                break;
            default:
                echo "<input class='form-control' autocomplete='off' 
                                type='text' name='frm_$colFields' 
                                value='" . htmlentities($value) . "' /></br>";
                break;
        }
    }

    /**
     * Returns true if array is an associative array
     * @param array $arr
     * @return bool
     */
    private function isAssoc(array $arr): bool
    {
        if (array() === $arr) {
            return false;
        }
        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    /**
     * if true then the jobs are cleaned out of the queue when they are stopped.
     *
     * @return bool
     */
    public function isAutoGarbage(): bool
    {
        return $this->autoGarbage;
    }

    /**
     * It sets if the jobs must be clean automatically each time the job is stopped
     *
     * @param bool $autoGarbage
     */
    public function setAutoGarbage(bool $autoGarbage = true): void
    {
        $this->autoGarbage = $autoGarbage;
    }

    /**
     * Returns true if the database is active
     *
     * @return int (self::NODB =0, self::PDODB=1, self::DOCDB=2)
     */
    public function isDbActive(): ?int
    {
        return $this->dbActive;
    }

    /**
     * It sets the database as active. When we call setDb() then it is set as true automatically.
     *
     * @param int $dbActive =[self::NODB,self::PDODB,self::DOCDB][$i]
     */
    public function setDbActive(int $dbActive): void
    {
        $this->dbActive = ($this->dbActive === true) ? self::PDODB : $this->dbActive;
        //$this->dbActive = $dbActive;
    }

    /**
     * Returns true if is in debug mode.
     *
     * @return bool
     */
    public function isDebug(): bool
    {
        return $this->debug;
    }

    /**
     * Set the debug mode. By default the debug mode is false.
     *
     * @param bool $debug
     */
    public function setDebug(bool $debug = true): void
    {
        $this->debug = $debug;
    }

    /**
     * @return bool
     */
    public function isDebugAsArray(): bool
    {
        return $this->debugAsArray;
    }

    /**
     * @param bool $debugAsArray
     */
    public function setDebugAsArray(bool $debugAsArray = true): void
    {
        $this->debugAsArray = $debugAsArray;
    }

    /**
     * @return array
     */
    public function getDebugArray(): array
    {
        return $this->debugArray;
    }

    /**
     * @param array $debugArray
     */
    public function setDebugArray(array $debugArray): void
    {
        $this->debugArray = $debugArray;
    }

    /**
     * @param int $defaultInitState
     */
    public function setDefaultInitState(int $defaultInitState): void
    {
        $this->defaultInitState = $defaultInitState;
    }

    /**
     * @param Job $job
     *
     * @return int|string
     */
    public function getJobState(Job $job)
    {
        return $job->state;
    }

    /**
     * @param Job $job
     *
     * @return mixed
     */
    public function getJobStateName(Job $job)
    {
        return $this->states[$job->state];
    }

    /**
     * It sets the method called when the job change state
     *
     * @param callable $changeStateTrigger
     * @param string   $when =['after','before','instead'][$i]
     */
    public function setChangeStateTrigger(callable $changeStateTrigger, string $when = 'after'): void
    {
        $this->changeStateTrigger = $changeStateTrigger;
        $this->changeStateTriggerWhen = $when;
    }

    /**
     * It sets the method called when the job starts
     *
     * @param string   $when =['after','before','instead'][$i]
     * @param callable $startTrigger
     */
    public function setStartTrigger(callable $startTrigger, string $when = 'after'): void
    {
        $this->startTrigger = $startTrigger;
        $this->startTriggerWhen = $when;
    }

    /**
     * It sets the method called when job is paused
     *
     * @param callable $pauseTrigger
     * @param string   $when =['after','before','instead'][$i]
     */
    public function setPauseTrigger(callable $pauseTrigger, string $when = 'after'): void
    {
        $this->pauseTrigger = $pauseTrigger;
        $this->pauseTriggerWhen = $when;
    }

    public function callPauseTrigger($job)
    {
        return call_user_func($this->pauseTrigger, $this, $job);
    }

    /**
     * It sets the method called when the job stop. The method must have two arguments
     * <p>$this->setStopTrigger(function (StateMachineOne $smo, Job $job) { ... });</p>
     *
     * @param callable $stopTrigger
     * @param string   $when =['after','before','instead'][$i] If we want to call it after it's stop, before or instead
     *                       of
     *
     * @test void this(),'it must return nothing'
     */
    public function setStopTrigger(callable $stopTrigger, string $when = 'after'): void
    {
        //function(StateMachineOne $smo,Job $job) { return true; }
        $this->stopTrigger = $stopTrigger;
        $this->stopTriggerWhen = $when;
    }

    public function callStopTrigger($job)
    {
        return call_user_func($this->stopTrigger, $this, $job);
    }

    /**
     * It sets a function to returns the number of the process. By default, it is obtained by the database
     * or via an internal counter.
     *
     * @param callable $getNumberTrigger
     */
    public function setGetNumberTrigger(callable $getNumberTrigger): void
    {
        $this->getNumberTrigger = $getNumberTrigger;
    }

    /**
     * @return Transition[]
     */
    public function getTransitions(): array
    {
        return $this->transitions;
    }

    /**
     * @return Transition
     */
    public function getCurrentTransition(): Transition
    {
        return $this->transitions[$this->currentTransition];
    }

    //</editor-fold>
}

