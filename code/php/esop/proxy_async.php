#!/usr/bin/env php
<?php
error_reporting(E_ALL);

/**
 * 测试代理并发处理功能
 *
 * 需要配合 clear_test_log.sh 脚本
 * 清理 /tmp/{serv,cli}.log 下的调试日志
 * 清理 agent/toolmail 下 phptd 日志
 */

/**
 * require
 */
require_once __DIR__ . '/../daemon/daemon.php';

$count = 1; $url = '127.0.0.1'; $port = 8538;
$base = new EventBase();

$fork_num = 4;
$max_send_num = 50;
$send_interval = 5;

function send_data()
{
    global $count, $max_send_num, $send_interval;
    global $url, $port;
    global $base;

    $pid = posix_getpid();

    while (1) {
        if ($max_send_num < $count) {
            $count = 1;
            $base->exit();
            sleep($send_interval);
            continue;
            //exit(0);
        }

        $rand = random();
        $data = time() . "-{$rand}-{$pid}-{$count}";

        $bev = new EventBufferEvent($base, null,                                
            EventBufferEvent::OPT_CLOSE_ON_FREE | EventBufferEvent::OPT_DEFER_CALLBACKS);
        $bev->setTimeouts(3, 3);                     
        $bev->setCallbacks('readcb', null, 'eventcb');  
        $bev->enable(Event::READ|Event::WRITE);                                             
        $bev->connect("{$url}:{$port}");
        $bev->write($data . "\r\n");                                           
        $base->dispatch();

        lg("pid:{$pid},count:{$count}");
    }
}

function readcb($bev, $args)
{
    $ret = trim($bev->read(1024));
    lg("readcb: {$ret}");
    $bev->setCallbacks('readcb', 'writecb', 'eventcb');  
    $bev->enable(Event::WRITE);
}

function writecb($bev, $args)
{
    global $count;

    $bev->free();
    $count++;
}

function eventcb($bev, $events, $args)
{
    lg("events: {$events}");
}

function random()
{
    return md5(microtime(true) . mt_rand(0, 99999999));
}

function lg($msg)
{
    $file = '/tmp/cli';
    file_put_contents($file, $msg . "\n", FILE_APPEND | LOCK_EX);
}

function help()
{
    echo './' . basename(__FILE__) . ' [r $1|k|s|h]' . PHP_EOL;
    echo <<<HELP
    r      run daemon
            $1 进程数
    k      kill daemon
    h      help
HELP;
}

function status()
{
    exec("ps -ef | grep 'php r'", $out);
    print_r(implode("\n", $out)."\n");
}

//send_data();
//exit(0);

$daemon = new Daemon([ 'func' => 'send_data' ]);

if (isset($argv[1])) {
    if ('r' === $argv[1]) {
        if (isset($argv[2])) {
            $daemon->set_option('fork_num', $argv[2]);
        } else {
            $daemon->set_option('fork_num', $fork_num);
        }

        $daemon->fork_proc();
    } elseif ('k' === $argv[1]) {
        $daemon->kill_proc();
    } elseif ('s' === $argv[1]) {
        status();
    } else {
        help();
    }
} else {
    help();
}

