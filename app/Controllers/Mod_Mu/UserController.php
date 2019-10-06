<?php

namespace App\Controllers\Mod_Mu;

use App\Models\Node;
use App\Models\TrafficLog;
use App\Models\User;
use App\Models\NodeOnlineLog;
use App\Models\Ip;
use App\Models\DetectLog;
use App\Models\DetectBanLog;
use App\Controllers\BaseController;
use App\Services\Config;
use App\Utils\Tools;
use App\Utils\URL;
use App\Services\MalioConfig;

class UserController extends BaseController
{
    // User List
    public function index($request, $response, $args)
    {
        $params = $request->getQueryParams();

        $node_id = $params['node_id'];
        $node = new Node();
        if ($node_id == '0') {
            $node = Node::where('node_ip', $_SERVER['REMOTE_ADDR'])->first();
            $node_id = $node->id;
        } else {
            $node = Node::where('id', '=', $node_id)->first();
            if ($node == null) {
                $res = [
                    'ret' => 0
                ];
                return $this->echoJson($response, $res);
            }
        }
        $node->node_heartbeat = time();
        $node->save();

        // 节点流量耗尽则返回 null
        if (($node->node_bandwidth_limit != 0) && $node->node_bandwidth_limit < $node->node_bandwidth) {
            $users = null;

            $res = [
                'ret' => 1,
                'data' => $users
            ];
            return $this->echoJson($response, $res);
        }

        if (in_array($node->sort, [0, 10]) && $node->mu_only != -1) {
            $mu_port_migration = Config::get('mu_port_migration');
        } else {
            $mu_port_migration = 'false';
        }

        /*
         * 1. 请不要把管理员作为单端口承载用户
         * 2. 请不要把真实用户作为单端口承载用户
         */
        $users_raw = User::where(
            static function ($query) use ($node, $mu_port_migration) {
                if ($mu_port_migration == 'true') {
                    $query->where(
                        static function ($query1) use ($node) {
                            if ($node->node_group != 0) {
                                $query1->where('class', '>=', $node->node_class)
                                    ->where('node_group', '=', $node->node_group)
                                    ->where('is_multi_user', '=', 0)
                                    ->where('is_admin', 0);
                            } else {
                                $query1->where('class', '>=', $node->node_class)
                                    ->where('is_multi_user', '=', 0)
                                    ->where('is_admin', 0);
                            }
                        }
                    );
                } else {
                    $query->where(
                        static function ($query1) use ($node) {
                            if ($node->node_group != 0) {
                                $query1->where('class', '>=', $node->node_class)
                                    ->where('node_group', '=', $node->node_group);
                            } else {
                                $query1->where('class', '>=', $node->node_class);
                            }
                        }
                    )->orwhere('is_admin', 1);
                }
            }
        )->where('enable', 1)->where("detect_ban", 0)->where('expire_in', '>', date('Y-m-d H:i:s'))->get();

        // 单端口承载用户
        if ($mu_port_migration == 'true') {
            $mu_users_raw = User::where(
                static function ($query) use ($node) {
                    $query->where(
                        static function ($query1) use ($node) {
                            if ($node->node_group != 0) {
                                $query1->where('class', '>=', $node->node_class)
                                    ->where('node_group', '=', $node->node_group)
                                    ->where('is_multi_user', '>', 0);
                            } else {
                                $query1->where('class', '>=', $node->node_class)
                                    ->where('is_multi_user', '>', 0);
                            }
                        }
                    )->orwhere('is_admin', 1);
                }
            )->where('enable', 1)->where("detect_ban", 0)->where('expire_in', '>', date('Y-m-d H:i:s'))->get();

            $muPort = Tools::get_MuOutPortArray($node->server);
            if ($muPort['type'] == 0) {
                foreach ($mu_users_raw as $user_raw) {
                    if ($user_raw->is_multi_user != 0 && in_array($user_raw->port, array_keys($muPort['port']))) {
                        $user_raw->port = $muPort['port'][$user_raw->port];
                    }
                    $users_raw[] = $user_raw;
                }
            } else {
                foreach ($mu_users_raw as $user_raw) {
                    if ($user_raw->is_multi_user != 0) {
                        $user_raw->port = ($user_raw->port + $muPort['type']);
                    }
                    $users_raw[] = $user_raw;
                }
            }
        }

        $key_list = array('email', 'method', 'obfs', 'obfs_param', 'protocol', 'protocol_param',
            'forbidden_ip', 'forbidden_port', 'node_speedlimit', 'disconnect_ip',
            'is_multi_user', 'id', 'port', 'passwd', 'u', 'd');

        $users = array();

        if (Config::get('keep_connect') == 'true') {
            foreach ($users_raw as $user_raw) {
                if ($user_raw->transfer_enable > $user_raw->u + $user_raw->d) {
                    $user_raw = Tools::keyFilter($user_raw, $key_list);
                    $user_raw->uuid = $user_raw->getUuid();
                    if (MalioConfig::get('enable_webapi_email_hash') == true) {
                        $user_raw->email = md5($user_raw->email);
                    }
                    $users[] = $user_raw;
                } else {
                    // 流量耗尽用户限速至 1Mbps
                    $user_raw = Tools::keyFilter($user_raw, $key_list);
                    $user_raw->uuid = $user_raw->getUuid();
                    $user_raw->node_speedlimit = 1;
                    if (MalioConfig::get('enable_webapi_email_hash') == true) {
                        $user_raw->email = md5($user_raw->email);
                    }
                    $users[] = $user_raw;
                }
            }
        } else {
            foreach ($users_raw as $user_raw) {
                if ($user_raw->transfer_enable > $user_raw->u + $user_raw->d) {
                    $user_raw = Tools::keyFilter($user_raw, $key_list);
                    $user_raw->uuid = $user_raw->getUuid();
                    if (MalioConfig::get('enable_webapi_email_hash') == true) {
                        $user_raw->email = md5($user_raw->email);
                    }
                    $users[] = $user_raw;
                }
            }
        }

        $res = [
            'ret' => 1,
            'data' => $users
        ];
        return $this->echoJson($response, $res);
    }

    //   Update Traffic
    public function addTraffic($request, $response, $args)
    {
        $params = $request->getQueryParams();

        $data = $request->getParam('data');
        $this_time_total_bandwidth = 0;
        $node_id = $params['node_id'];
        if ($node_id == '0') {
            $node = Node::where('node_ip', $_SERVER['REMOTE_ADDR'])->first();
            $node_id = $node->id;
        }
        $node = Node::find($node_id);

        if ($node == null) {
            $res = [
                'ret' => 0
            ];
            return $this->echoJson($response, $res);
        }

        if (count($data) > 0) {
            foreach ($data as $log) {
                $u = $log['u'];
                $d = $log['d'];
                $user_id = $log['user_id'];

                $user = User::find($user_id);

                if ($user == null) {
                    continue;
                }

                $user->t = time();
                $user->u += $u * $node->traffic_rate;
                $user->d += $d * $node->traffic_rate;
                $this_time_total_bandwidth += $u + $d;
                if (!$user->save()) {
                    $res = [
                        'ret' => 0,
                        'data' => 'update failed',
                    ];
                    return $this->echoJson($response, $res);
                }

                // log
                $traffic = new TrafficLog();
                $traffic->user_id = $user_id;
                $traffic->u = $u;
                $traffic->d = $d;
                $traffic->node_id = $node_id;
                $traffic->rate = $node->traffic_rate;
                $traffic->traffic = Tools::flowAutoShow(($u + $d) * $node->traffic_rate);
                $traffic->log_time = time();
                $traffic->save();
            }
        }

        $node->node_bandwidth += $this_time_total_bandwidth;
        $node->save();

        $online_log = new NodeOnlineLog();
        $online_log->node_id = $node_id;
        $online_log->online_user = count($data);
        $online_log->log_time = time();
        $online_log->save();

        $res = [
            'ret' => 1,
            'data' => 'ok',
        ];
        return $this->echoJson($response, $res);
    }

    public function addAliveIp($request, $response, $args)
    {
        $params = $request->getQueryParams();

        $data = $request->getParam('data');
        $node_id = $params['node_id'];
        if ($node_id == '0') {
            $node = Node::where('node_ip', $_SERVER['REMOTE_ADDR'])->first();
            $node_id = $node->id;
        }
        $node = Node::find($node_id);

        if ($node == null) {
            $res = [
                'ret' => 0
            ];
            return $this->echoJson($response, $res);
        }
        if (count($data) > 0) {
            foreach ($data as $log) {
                $ip = $log['ip'];
                $userid = $log['user_id'];

                // log
                $ip_log = new Ip();
                $ip_log->userid = $userid;
                $ip_log->nodeid = $node_id;
                $ip_log->ip = $ip;
                $ip_log->datetime = time();
                $ip_log->save();
            }
        }

        $res = [
            'ret' => 1,
            'data' => 'ok',
        ];
        return $this->echoJson($response, $res);
    }

    public function addDetectLog($request, $response, $args)
    {
        $params = $request->getQueryParams();

        $data = $request->getParam('data');
        $node_id = $params['node_id'];
        if ($node_id == '0') {
            $node = Node::where('node_ip', $_SERVER['REMOTE_ADDR'])->first();
            $node_id = $node->id;
        }
        $node = Node::find($node_id);

        if ($node == null) {
            $res = [
                'ret' => 0
            ];
            return $this->echoJson($response, $res);
        }

        if (Config::get('enable_auto_detect_ban') == 'true') {
            $detect_Users = array();
            if (count($data) > 0) {
                foreach ($data as $log) {
                    $list_id = $log['list_id'];
                    $user_id = $log['user_id'];

                    // log
                    $detect_log = new DetectLog();
                    $detect_log->user_id = $user_id;
                    $detect_log->list_id = $list_id;
                    $detect_log->node_id = $node_id;
                    $detect_log->datetime = time();
                    $detect_log->save();

                    $User = User::find($user_id);
                    if ($User == null) {
                        continue;
                    }
                    if (!in_array($user_id, $detect_Users)) {
                        $detect_Users[] = $user_id;
                    }
                    $User->all_detect_number++;
                    $User->save();
                }
            }
            if (count($detect_Users) > 0) {
                self::DetectBan($detect_Users);
            }
        } else {
            if (count($data) > 0) {
                foreach ($data as $log) {
                    $list_id = $log['list_id'];
                    $user_id = $log['user_id'];

                    // log
                    $detect_log = new DetectLog();
                    $detect_log->user_id = $user_id;
                    $detect_log->list_id = $list_id;
                    $detect_log->node_id = $node_id;
                    $detect_log->datetime = time();
                    $detect_log->save();
                }
            }
        }

        $res = [
            'ret' => 1,
            'data' => 'ok',
        ];
        return $this->echoJson($response, $res);
    }

    public function DetectBan($detect_Users)
    {
        foreach ($detect_Users as $user_id) {
            $User = User::find($user_id);
            if ($User == null) {
                continue;
            }
            if ($User->detect_ban == 1 || ($User->is_admin && Config::get('auto_detect_ban_allow_admin') == 'true') || in_array($User->id, Config::get('auto_detect_ban_allow_users'))) {
                continue;
            }
            if (Config::get('auto_detect_ban_type') == '1') {
                $last_DetectBanLog = DetectBanLog::where('user_id', $user_id)->orderBy("id", "desc")->first();
                $last_all_detect_number = (
                    $last_DetectBanLog == null
                    ? 0
                    : (int) $last_DetectBanLog->all_detect_number
                );
                $detect_number = ($User->all_detect_number - $last_all_detect_number);
                if ($detect_number >= Config::get('auto_detect_ban_number')) {
                    $last_detect_ban_time = $User->last_detect_ban_time;
                    $end_time = date('Y-m-d H:i:s');
                    $User->detect_ban = 1;
                    $User->last_detect_ban_time = $end_time;
                    $User->save();
                    $DetectBanLog = new DetectBanLog();
                    $DetectBanLog->user_name = $User->user_name;
                    $DetectBanLog->user_id = $User->id;
                    $DetectBanLog->email = $User->email;
                    $DetectBanLog->detect_number = $detect_number;
                    $DetectBanLog->ban_time = Config::get('auto_detect_ban_time');
                    $DetectBanLog->start_time = strtotime($last_detect_ban_time);
                    $DetectBanLog->end_time = strtotime($end_time);
                    $DetectBanLog->all_detect_number = $User->all_detect_number;
                    $DetectBanLog->save();
                }
            } else {
                $number = $User->all_detect_number;
                $tmp = 0;
                foreach (Config::get('auto_detect_ban') as $key => $value) {
                    if ($number >= $key) {
                        if ($key >= $tmp) {
                            $tmp = $key;
                        }
                    }
                }
                if ($tmp != 0) {
                    if (Config::get('auto_detect_ban')[$tmp]['type'] == 'kill') {
                        $User->kill_user();
                    } else {
                        $last_detect_ban_time = $User->last_detect_ban_time;
                        $end_time = date('Y-m-d H:i:s');
                        $User->detect_ban = 1;
                        $User->last_detect_ban_time = $end_time;
                        $User->save();
                        $DetectBanLog = new DetectBanLog();
                        $DetectBanLog->user_name = $User->user_name;
                        $DetectBanLog->user_id = $User->id;
                        $DetectBanLog->email = $User->email;
                        $DetectBanLog->detect_number = $number;
                        $DetectBanLog->ban_time = Config::get('auto_detect_ban')[$tmp]['time'];
                        $DetectBanLog->start_time = strtotime('1989-06-04 00:05:00');
                        $DetectBanLog->end_time = strtotime($end_time);
                        $DetectBanLog->all_detect_number = $number;
                        $DetectBanLog->save();
                    }
                }
            }
        }
    }
}
