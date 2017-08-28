<?php
/**
 *  Gizwits noti client
 */

$gVersion = "1708100933";
$auth_id = "yj8t4R0CR4e/kHv6IItnnA"; //改成自己设备noti服务的auth_id
$auth_secret = "Jrf3h7EpTtKH6dVZAddPmA"; //改成自己设备noti服务的auth_id
$product_key = "f15915ce03424adda38fdfd5c1a6390a"; //改成自己设备noti服务的auth_id

$subkey = "123456789"; //改成自己公司业务逻辑名字(好记住就可以),也可以默认当前值

$event_timeout = 2;
$heart_timeout= 120;

/**
 * 建立SSL连接
 *
 * @return null|SSL连接对象
 */
function connect() {
    $errno = '';
    $errstr = '';
    $timeout = 10;
    $stream = null;
    global $event_timeout;

    //设置SSL不认证
    $contextOptions = array(
        'ssl' => array(
            'verify_peer' => false,
            'verify_peer_name' => false
        )
    );

    //创建SSL 连接
    $streamContext = stream_context_create($contextOptions);
    $host = 'ssl://snoti.gizwits.com:2017';
    $stream = stream_socket_client($host, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $streamContext);
    if (!$stream) {
        echo "$errstr ($errno)\n";
    } else {
        stream_set_timeout($stream, $event_timeout);
    }

    return $stream;
}

/**
 * noti服务登录验证
 * @param $clientsocket SSL连接
 * @return 0:登录成功; 1:登录失败
 */
function login($clientsocket) {
    global $auth_id;
    global $auth_secret;
    global $product_key;
    global $subkey;

    $logininfo = array("cmd" => "login_req",
        "prefetch_count" => 100,
        "data" => array(array(
            "product_key" => $product_key, "auth_id" => $auth_id, "auth_secret" => $auth_secret,
            "subkey" => $subkey, "events" => array("device.online","datapoints.changed","device.offline","device.status.raw",
                "device.status.kv","device.attr_fault","device.attr_alert")
        )));


//    $logininfo = array("cmd" => "login_req",
//        "prefetch_count" => 100,
//        "data" => array(array(
//            "product_key" => $product_key, "auth_id" => $auth_id, "auth_secret" => $auth_secret,
//            "subkey" => $subkey, "events" => array("device.attr_fault","device.attr_alert","device.status.kv")
//        )));

    if ($clientsocket) {
        $jsonlogininfo = strval(json_encode($logininfo)."\n");

        fwrite($clientsocket, $jsonlogininfo);
        //flush($clientsocket);
        print_r("send data success: ".$jsonlogininfo);

        $response = '';
        do {
            $line = fgets($clientsocket);
            $response .= $line;
        } while (null !== $line && false !== $line && ' ' != $line{3});

        print_r($response);
        return dataProcess($clientsocket, $response, null, null, 0);
    } else {
        return 1;
    }

}

function loginNotiServer() {
    $fp = 0;
    $count = 0;
    while(1) {
        if ($count > 100) {
            echo "connect ".$count." times but all failed\n";
            break;
        }
        $fp = connect();
        if ($fp) {
            $res = login($fp);
            if (!$res) {
                echo "login success\n";
                break;
            } else {
                fclose($fp);
                $fp = 0;
                //连接失败,则等待1s后再次尝试连接
                sleep(1);
            }
        } else {
            //连接失败,则等待1s后再次尝试连接
            sleep(1);
        }

        ++$count;
    }

    return $fp;
}

function messageAck($fp, $id) {
    $data = array("cmd" => "event_ack", "delivery_id" => $id);
    $jsonMessageAck = strval(json_encode($data)."\n");

    fwrite($fp, $jsonMessageAck);
    //flush($clientsocket);
    print_r("send data success: ".$jsonMessageAck);
}

function heart($fp) {
    $data = array("cmd" => "ping");
    $jsonHeartRsp = strval(json_encode($data)."\n");

    fwrite($fp, $jsonHeartRsp);
    print_r("send data success: ".$jsonHeartRsp);

}

/**
 * 该函数处理noti云推送消息,客户可在该函数内进行二次开发扩展
 * 不同的事件类型处理对应的事件,处理事件函数可由客户具体实现。
 * @param $fp
 * @param $data
 */
function eventPushProcess($fp, $data) {
    $delivery_id = $data->delivery_id;
    //去掉不需要的字段
    unset($data->cmd);
    unset($data->delivery_id);

    $type = $data->event_type;
    switch ($type) {
        case "datapoints_changed":
            echo "no process\n";
            break;
        case "device_online":
            echo "device online\n";
            break;
        case "device_offline":
            echo "device offline\n";
            break;
        case "attr_fault":
        case "attr_alert":
            echo "attr_alert/attr_fault\n";
            //$data已经是对象数据
            //dataReport($data);
            break;
        case "device_status_raw":
            echo "device_status_raw\n";
            break;
        case "device_status_kv":
            echo "device_status_kv\n";
            break;
        default:
            break;
    }

    //回复ACK
    messageAck($fp, $delivery_id);
    echo "ACK send OK!\n";
}

function clientEventProcess($fp, $data) {
    fwrite($fp, $data);
    //flush($clientsocket);
    print_r("send data success: ".$data);
}

function writeClientRes($fp, $data) {
    $dataStr = strval(json_encode($data)."\n");
    fwrite($fp, $dataStr);
    //flush($clientsocket);
    print_r("send data success: ".$dataStr);
}
/**
 * 对noti服务推送的事件和client发送数据处理
 * @param $fp 当前触发事件socket
 * @param $data 接收到的数据
 * @param $conserver noti服务socket
 * @param $conclient client socket
 * @param $flag  是client socket事件处理标识
 * @return int
 */
function dataProcess($fp, $data, $conserver, $conclient, $flag) {
    $isLogin = 1;
    if ($fp) {
        if ($flag == 1) {
            echo "remote_control_req";
            clientEventProcess($conserver, $data);
        } else {
            $varArray = explode("\n", $data);
            foreach ($varArray as $value) {
                //针对不是josn格式字符串处理--心跳ACK,调用客户函数处理
                //暂未加
                $jsonData = json_decode($value);
                if ($jsonData == "" || $jsonData->cmd == "") {
                    continue;
                }

                $cmd = $jsonData->cmd;
                echo $cmd."\n";
                switch ($cmd) {
                    case "login_res":
                        if ($jsonData->data->result) {
                            $isLogin = 0;
                        }
                        break;
                    case "pong":
                        echo "heart ack\n";
                        break;
                    case "event_push":
                        eventPushProcess($fp, $jsonData);
                        break;
                    case "remote_control_res":
                        echo "remote_control_res from noti server";
                        writeClientRes($conclient, $jsonData);
                        break;
                    case "invalid_msg":
                        echo "invalid_msg, errorCode: ".$jsonData->error_code.", msg:".$jsonData->msg;
                        break;
                    default:
                        echo "invalid cmd\n";
                        break;
                }
            }
        }
    } else {
        echo "invalid parmas\n";
    }

    return $isLogin;
}

/**
 * 监听套接字创建
 * @return null|监听套接字
 */
function getListenSocket() {
    $count = 0;
    $errno = "";
    $errstr = "";
    $socket = null;
    global $event_timeout;
    while (1) {
        if ($count > 3) {
            echo "connect ".$count." times but all failed\n";
            break;
        }

        $socket = stream_socket_server("tcp://0.0.0.0:8000", $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
        if (!$socket) {
            echo "$errstr ($errno)---->server actived\n";
            break;
        } else {
            stream_set_timeout($socket, $event_timeout);
            echo "local server socket create success\n";
            break;
        }

        ++$count;
    }

    return $socket;
}

function baseInfo() {
    global $gVersion;
    echo "\n***************\n";
    echo "Gizwits noti client\n";
    echo "current version ". $gVersion."\n";
    echo "***************\n";
    echo "\n";
}
/**
 * noti服务推送事件处理服务
 */
function notiServerProcess() {
    $lastHeartSendTime = 0;
    $timeout = 5;
    $clientflag = 0;
    $cnt = 0;
    $socketClient = 0;
    global $event_timeout;
    global $heart_timeout;

    baseInfo();
    $socketListen = getListenSocket();
    if (!$socketListen) {
        echo "socketListen create failed, exit\n";
        return;
    }
    $connect = loginNotiServer();
    if (!$connect) {
        echo "notiserver socket create failed, exit\n";
        return ;
    }

    while(1) {

        if (!$socketListen) {
            $socketListen = getListenSocket();
            if (!$socketListen) {
                echo "socket create failed, exit\n";
                return;
            }
        }

        if (!$connect) {
            $connect = loginNotiServer();
            if (!$connect) {
                echo "connect failed, exit\n";
                return;
            }
        }

        if (!$socketClient) {
            $sockets = array("socket" => $connect, "sokcetListen" => $socketListen);
        } else {
            $sockets = array("socket" => $connect, "sokcetlisten" => $socketListen, "socketclient" => $socketClient);
        }

        $read = $sockets;
        $write = NULL;
        $error = NULL;

        $num2 = stream_select($read, $write, $error, $timeout);
        if ($num2 > 0) {
            echo "event process\n";
            foreach ($read as $r) {
                if ($r == $socketListen) {
                    $socketClient = stream_socket_accept($r);
                    stream_set_timeout($socketClient, $event_timeout);
                } else {
                    //读取数据
                    echo "get data start\n";
                    $response = '';
                    do {
                        $line = fgets($r);
                        $response .= $line;
                    } while (null !== $line && false !== $line && ' ' != $line{3});
                    if ('' === $response) {
                        if ($r == $socketClient ) {
                            echo "close socketClient: ".$socketClient."\n";
                            fclose($socketClient);
                            $socketClient = 0;
                            break;
                        }

                        if ($r == $connect ) {
                            echo "close connect: ".$connect."\n";
                            fclose($connect);
                            $connect = 0;
                            break;
                        }

                    }

                    echo "get data over--data: ".$response;
                    if ($r == $socketClient) {
                        $clientflag = 1;
                    }
                    dataProcess($r, $response, $connect, $socketClient, $clientflag);
                    if ($clientflag == 1) {
                        $clientflag = 0;
                    }

                }
            }
        } else if (false === $num2){
            echo "stream_select() failed\n";
        } else {
            echo "no event process\n";
        }

        //时间到4分钟则发送心跳
        if ((time() - $lastHeartSendTime) > $heart_timeout) {
            heart($connect);
            $lastHeartSendTime = time();
        }

    }

    fclose($connect);
}

notiServerProcess();
