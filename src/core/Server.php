<?php
namespace Tars\fpm\core;

use Tars\monitor\StatFWrapper;
use Tars\report\ServerFSync;
use Tars\report\ServerInfo;
use Tars\Utils;
use Swoole\Coroutine\FastCGI\Proxy;

class Server
{
    protected $tarsConfig;
    protected $sw;
    protected $masterPidFile;
    protected $managerPidFile;

    protected $application;
    protected $serverName = '';
    protected $protocolName = 'tars';

    protected $host = '0.0.0.0';
    protected $port = '8088';
    protected $worker_num = 4;
    protected $servType = 'tcp';

    protected $setting;

    protected $dataPath;
    protected $basePath;
    protected $entrance;
    protected $servicesInfo;
    protected static $paramInfos;

    protected static $impl;
    protected $protocol;
    protected $timers;

    public function __construct($conf, $table = null)
    {
        $tarsServerConf = $conf['tars']['application']['server'];
        $tarsClientConf = $conf['tars']['application']['client'];

        $this->dataPath = $tarsServerConf['datapath'];
        $this->basePath = $tarsServerConf['basepath'];
        $this->entrance = $tarsServerConf['entrance'];

        $this->servicesInfo = $tarsServerConf['servicesInfo'];

        $this->tarsConfig = $conf;
        $this->application = $tarsServerConf['app'];
        $this->serverName = $tarsServerConf['server'];

        $this->host = $tarsServerConf['listen'][0]['bIp'];
        $this->port = $tarsServerConf['listen'][0]['iPort'];
        $this->fatcgiPort = intval($this->port)+1;

        $this->setting = $tarsServerConf['setting'];

        $this->protocolName = $tarsServerConf['protocolName'];
        $this->servType = $tarsServerConf['servType'];
        $this->table = $table;
        $this->worker_num = $this->setting['worker_num'];
    }

    public function start()
    {
        $documentRoot = dirname(__FILE__,5).'DocumentRoot'; // fpm项目目录的绝对路径
        $server = new Server($this->host, $this->port, SWOOLE_BASE);
        $this->sw = $server;
        $server->set([
            Constant::OPTION_WORKER_NUM => $this->worker_num,
            Constant::OPTION_HTTP_PARSE_COOKIE => false,
            Constant::OPTION_HTTP_PARSE_POST => false,
            Constant::OPTION_DOCUMENT_ROOT => $documentRoot,
            Constant::OPTION_ENABLE_STATIC_HANDLER => true,
            Constant::OPTION_STATIC_HANDLER_LOCATIONS => ['/'],
        ]);
        $proxy = new Proxy($this->host.':'.$this->fatcgiPort, $documentRoot);
        $this->sw->on('request', function (Request $request, Response $response) use ($proxy) {
            $proxy->pass($request, $response);
        });
        $this->sw->on('Start', array($this, 'onMasterStart'));
        $this->sw->on('ManagerStart', array($this, 'onManagerStart'));
        $this->sw->on('WorkerStart', array($this, 'onWorkerStart'));
        $this->sw->on('Connect', array($this, 'onConnect'));
        $this->sw->on('Receive', array($this, 'onReceive'));
        $this->sw->on('Close', array($this, 'onClose'));
        $this->sw->on('WorkerStop', array($this, 'onWorkerStop'));
        $this->sw->on('Task', array($this, 'onTask'));
        $this->sw->on('Finish', array($this, 'onFinish'));

        $this->sw->start();
    }

    public function stop()
    {
    }

    public function restart()
    {
    }

    public function reload()
    {
    }

    private function _setProcessName($name)
    {
        if (function_exists('cli_set_process_title')) {
            cli_set_process_title($name);
        } elseif (function_exists('swoole_set_process_name')) {
            swoole_set_process_name($name);
        } else {
            trigger_error(__METHOD__.' failed. require cli_set_process_title or swoole_set_process_name.');
        }
    }

    public function onMasterStart($server)
    {
        $this->_setProcessName($this->application.'.'.$this->serverName.': master process');
        file_put_contents($this->masterPidFile, $server->master_pid);
        file_put_contents($this->managerPidFile, $server->manager_pid);

        // 初始化的一次上报
        $this->keepaliveinit();

        //拉取配置
        $this->loadTarsConfig();
    }

    public function onManagerStart($server)
    {
        // rename manager process
        $this->_setProcessName($this->application.'.'.$this->serverName.': manager process');
    }

    public function onWorkerStart($server, $workerId)
    {
        if ($workerId >= $this->worker_num) {
            $this->_setProcessName($this->application.'.'.$this->serverName.': task worker process');
        } else {
            $this->_setProcessName($this->application.'.'.$this->serverName.': event worker process');
        }

        if ($workerId == 0) {
            // 将定时上报的任务投递到task worker 0,只需要投递一次
            $result = Utils::parseNodeInfo($this->tarsConfig['tars']['application']['server']['node']);
            $this->sw->task(
                [
                    'application' => $this->application,
                    'serverName' => $this->serverName,
                    'masterPid' => $server->master_pid,
                    'adapter' => $this->tarsConfig['tars']['application']['server']['adapters'][0]['adapterName'],
                    'mode' => $result['mode'],
                    'host' => $result['host'],
                    'port' => $result['port'],
                    'timeout' => $result['timeout'],
                    'objName' => $result['objName'],
                    'client' => $this->tarsConfig['tars']['application']['client']
                ], 0);
        }
    }

    public function onConnect($server, $fd, $fromId)
    {
    }

    public function onTask($server, $taskId, $fromId, $data)
    {
        switch ($taskId) {
            // 进行定时上报
            case 0:{
                $application = $data['application'];
                $serverName = $data['serverName'];
                $masterPid = $data['masterPid'];
                $adapter = $data['adapter'];
                $mode = $data['mode'];
                $host = $data['host'];
                $port = $data['port'];
                $timeout = $data['timeout'];
                $objName = $data['objName'];

                \swoole_timer_tick(10000, function () use ($application, $serverName, $masterPid, $adapter, $mode, $host, $port, $timeout, $objName) {
                    // 进行一次上报
                    $serverInfo = new ServerInfo();
                    $serverInfo->adapter = $adapter;
                    $serverInfo->application = $application;
                    $serverInfo->serverName = $serverName;
                    $serverInfo->pid = $masterPid;

                    $serverF = new ServerFSync($host, $port, $objName);
                    $serverF->keepAlive($serverInfo);

                    $adminServerInfo = new ServerInfo();
                    $adminServerInfo->adapter = 'AdminAdapter';
                    $adminServerInfo->application = $application;
                    $adminServerInfo->serverName = $serverName;
                    $adminServerInfo->pid = $masterPid;
                    $serverF->keepAlive($adminServerInfo);
                });

                //主调定时上报
                $locator = $data['client']['locator'];
                $socketMode = 2;
                $statServantName = $data['client']['stat'];
                $reportInterval = $data['client']['report-interval'];
                \swoole_timer_tick($reportInterval,
                    function () use ($locator, $socketMode, $statServantName, $serverName, $reportInterval) {
                        try {
                            $statFWrapper = new StatFWrapper($locator, $socketMode, $statServantName, $serverName,
                                $reportInterval);
                            $statFWrapper->sendStat();
                        } catch (\Exception $e) {
                            var_dump((string) $e);
                        }
                    });

                break;
            }
            default:{
                break;
            }
        }
    }

    public function onFinish($server, $taskId, $data)
    {
    }

    public function onClose($server, $fd, $fromId)
    {
    }

    public function onWorkerStop($server, $workerId)
    {
    }

    public function onTimer($server, $interval)
    {
    }

    public function onReceive($server, $fd, $fromId, $data)
    {
    }

    public function onRequest($request, $response)
    {
    }

    //拉取配置到指定目录
    public function loadTarsConfig(){
        try{
            $servicesInfo = $this->servicesInfo;
            if( !empty($servicesInfo) && isset($servicesInfo['saveTarsConfigFileDir']) && isset($servicesInfo['saveTarsConfigFileName']) ){
                $fileNameArr = array_filter($servicesInfo['saveTarsConfigFileName']);
                $app = $this->tarsConfig['tars']['application']['server']['app'];
                $server = $this->tarsConfig['tars']['application']['server']['server'];

                if( !empty($fileNameArr) && $app!='' && $server!='' ){
                    $config = new \Tars\client\CommunicatorConfig();
                    $locator = $this->tarsConfig['tars']['application']['client']['locator'];
                    $moduleName = $this->tarsConfig['tars']['application']['client']['modulename'];
                    $config->setLocator($locator);
                    $config->setModuleName($moduleName);
                    $config->setSocketMode(2);

                    $conigServant = new \Tars\config\ConfigServant($config);

                    foreach ( $fileNameArr as $filename ){
                        $savefile = $filename;
                        if( substr($filename,0,1) != DIRECTORY_SEPARATOR ){
                            //相对路径转绝对路径
                            $basePath = $this->tarsConfig['tars']['application']['server']['basepath'];
                            $savefile = $basePath.$servicesInfo['saveTarsConfigFileDir'].$filename;
                        }

                        $configStr = '';
                        $conigServant->loadConfig($app, $server, $filename, $configStr);
                        if( $configStr!='' ){ //保存文件
                            $file = fopen($savefile, "w");
                            fwrite($file, $configStr);
                            fclose($file);
                            var_dump("loadTarsConfig success : ".$savefile);
                        }
                    }
                }
            }
        }catch (\Exception $e){
            var_dump((string) $e);
            return false;
        }
    }

    // 这个东西需要单列出去
    public function keepaliveinit()
    {
        // 加载tars需要的文件 - 最好是通过autoload来加载
        // 初始化的上报
        $serverInfo = new ServerInfo();
        $serverInfo->adapter = $this->tarsConfig['tars']['application']['server']['adapters'][0]['adapterName'];
        $serverInfo->application = $this->tarsConfig['tars']['application']['server']['app'];
        $serverInfo->serverName = $this->tarsConfig['tars']['application']['server']['server'];
        $serverInfo->pid = $this->sw->master_pid;

        // 解析出node上报的配置 tars.tarsnode.ServerObj@tcp -h 127.0.0.1 -p 2345 -t 10000
        $result = Utils::parseNodeInfo($this->tarsConfig['tars']['application']['server']['node']);
        $objName = $result['objName'];
        $host = $result['host'];
        $port = $result['port'];

        $serverF = new ServerFSync($host, $port, $objName);
        $serverF->keepAlive($serverInfo);
        $serverInfo->adapter = 'AdminAdapter';
        $serverF->keepAlive($serverInfo);
    }
}
