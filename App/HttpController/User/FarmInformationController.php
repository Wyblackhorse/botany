<?php


namespace App\HttpController\User;


use App\Model\AccountNumberModel;
use App\Model\FarmModel;
use App\Model\ToolsModel;
use App\Tools\Tools;
use EasySwoole\ORM\DbManager;
use EasySwoole\ORM\Exception\Exception;
use EasySwoole\Pool\Exception\PoolEmpty;

/**
 * Class FarmInformationController
 * @package App\HttpController\User
 * 农场操作
 */
class FarmInformationController extends UserBase
{


    #刷单个植物或者全部的植物信息
    function refresh_botany()
    {
        $id = $this->request()->getParsedBody('id'); #需要刷新的 账号
        if (!$this->check_parameter($id, "账号id")) {
            return false;
        }
        try {
            return DbManager::getInstance()->invoke(function ($client) use ($id) {
                # 获取 农场的 接口
                $one = AccountNumberModel::invoke($client)->get(['id' => $id]);
                if (!$one) {
                    $this->writeJson(-101, [], "账户id 不存在");
                    return false;
                }
                $token_value = $one['token_value'];
                for ($i = 0; $i < 5; $i++) {
                    $client = new \EasySwoole\HttpClient\HttpClient('https://backend-farm.plantvsundead.com/farms?limit=10&offset=0');
                    $headers = array(
                        'authority' => 'backend-farm.plantvsundead.com',
                        'sec-ch-ua' => '"Google Chrome";v="93", " Not;A Brand";v="99", "Chromium";v="93"',
                        'accept' => 'application/json, text/plain, */*',
                        'authorization' => $token_value,
                        'sec-ch-ua-mobile' => '?0',
                        'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/93.0.4577.82 Safari/537.36',
                        'sec-ch-ua-platform' => '"Windows"',
                        'origin' => 'https://marketplace.plantvsundead.com',
                        'sec-fetch-site' => 'same-site',
                        'sec-fetch-mode' => 'cors',
                        'sec-fetch-dest' => 'empty',
                        'referer' => 'https://marketplace.plantvsundead.com/',
                        'accept-language' => 'zh-CN,zh;q=0.9',
                    );
                    $client->setHeaders($headers, false, false);
                    $client->setTimeout(5);
                    $client->setConnectTimeout(10);
                    $response = $client->get();
                    $result = $response->getBody();


                    $data = json_decode($result, true);
                    if ($data && $data['status'] == 0) {
                        $this->response()->write($result);
                        #开始遍历 参数
                        return DbManager::getInstance()->invoke(function ($client) use ($data, $id) {
                            foreach ($data['data'] as $k => $value) {
                                # 判断 农场 没有有 这个 种子 id
                                $one = FarmModel::invoke($client)->get(['account_number_id' => $id, 'farm_id' => $value['_id']]);
                                $unix = 0;
                                if (isset($value['harvestTime'])) {
                                    $unix = str_replace(array('T', 'Z'), ' ', $value['harvestTime']);
                                }

                                $needWater = 2;
                                $hasSeed = 2;
                                if ($value['needWater']) {
                                    # 需要浇水  让进程去做这件事情
                                    $needWater = 1;
                                }
                                if ($value['hasSeed']) {
                                    #需要 放种子
                                    $hasSeed = 1;
                                }
                                # 这里需要判断 有没有乌鸦    如果有乌鸦 我需要 仍在 进程里面来做这件事!!!!
                                $add = [
                                    'account_number_id' => $id,
                                    'farm_id' => $value['_id'],
                                    'harvestTime' => strtotime($unix),
                                    'needWater' => $needWater,
                                    'hasSeed' => $hasSeed,
                                    'plant_type' => $value['plant']['type'],
                                    'updated_at' => time(),
                                    'stage' => $value['stage'], #paused 说明暂停 了 有乌鸦
                                    'totalHarvest' => $value['totalHarvest']
                                ];
                                #存在 只需要 做更新操作
                                if ($one) {
                                    $two = FarmModel::invoke($client)->where(['account_number_id' => $id, 'farm_id' => $value['_id']])->update($add);
                                } else {
                                    # 插入操作
                                    $add['created_at'] = time();
                                    $two = FarmModel::invoke($client)->data($add)->save();
                                }
                            }
                            return true;
                        });
                    }
                }
                $this->writeJson(-101, [], "获取失败");
                return false;
            });
        } catch (\Throwable $e) {
            $this->writeJson(-1, [], "异常:" . $e->getMessage());
            $this->WriteLogger($this->who['id'], 2, "接口 refresh_botany 抛出了异常:" . $e->getMessage());
            return false;
        }
    }


    #  获取农场信息
    function get_farmInformation()
    {

        $id = $this->request()->getParsedBody('id');
        $status = $this->request()->getParsedBody('status');
        if (!$this->check_parameter($id, "账户id")) {
            return false;
        }
        $limit = $this->request()->getParsedBody('limit');
        $page = $this->request()->getParsedBody('page');
        $action = $this->request()->getParsedBody('action');
        if (!$this->check_parameter($limit, "limit") || !$this->check_parameter($page, "page") || !$this->check_parameter($page, "action")) {
            return false;
        }
        try {
            return DbManager::getInstance()->invoke(function ($client) use ($limit, $page, $action, $id, $status) {
                if ($action == "select") {
                    $model = FarmModel::invoke($client)->limit($limit * ($page - 1), $limit)->withTotalCount();
                    $list = $model->all(['account_number_id' => $id, "status" => $status]);
                    $result = $model->lastQueryResult();
                    $total = $result->getTotalCount();
                    $return_data = [
                        "code" => 0,
                        "msg" => '',
                        'count' => $total,
                        'data' => $list
                    ];
                    $this->response()->write(json_encode($return_data));
                    return true;
                }

                $this->writeJson(-1, [], "非法参数");
                return false;

            });
        } catch (\Throwable $e) {
            $this->writeJson(-1, [], "异常:" . $e->getMessage());
            return false;
        }


    }


    # 所有账号 农场的信息
    function get_farmAccountInformation()
    {

        $limit = $this->request()->getParsedBody('limit');
        $page = $this->request()->getParsedBody('page');
        if (!$this->check_parameter($limit, "limit") || !$this->check_parameter($page, "page") || !$this->check_parameter($page, "action")) {
            return false;
        }
        try {
            return DbManager::getInstance()->invoke(function ($client) use ($limit, $page) {
                $model = AccountNumberModel::invoke($client)->limit($limit * ($page - 1), $limit)->withTotalCount();
                $list = $model->all(['user_id' => $this->who['id'],'status'=>1]);
                foreach ($list as $k => $value) {

                    $one = ToolsModel::invoke($client)->get(['account_number_id' => $value['id']]);
                    if ($one) {
                        $list[$k]['tools_water'] = $one['water'];
                        $list[$k]['tools_pot'] = $one['samll_pot'];
                        $list[$k]['tools_scarecrow'] = $one['scarecrow'];
                    }
                    $list[$k]['total'] = FarmModel::invoke($client)->where(['account_number_id' => $value['id'], 'status' => 1])->count();
                    $list[$k]['plant_type_one_total'] = FarmModel::invoke($client)->where(['account_number_id' => $value['id'], 'plant_type' => 1, 'status' => 1])->count();
                    $list[$k]['plant_type_two_total'] = FarmModel::invoke($client)->where(['account_number_id' => $value['id'], 'plant_type' => 2, 'status' => 1])->count();
                }
                $result = $model->lastQueryResult();
                $total = $result->getTotalCount();
                $return_data = [
                    "code" => 0,
                    "msg" => '',
                    'count' => $total,
                    'data' => $list
                ];
                $this->response()->write(json_encode($return_data));
                return true;
            });

        } catch (\Throwable $e) {
            $this->writeJson(-1, [], "异常:" . $e->getMessage());
            return false;
        }
    }


}