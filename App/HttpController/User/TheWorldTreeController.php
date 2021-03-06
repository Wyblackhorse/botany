<?php


namespace App\HttpController\User;


use App\Model\AccountNumberModel;
use App\Task\TheTreeFromWorldTask;
use App\Tools\Tools;
use EasySwoole\Http\AbstractInterface\Controller;
use EasySwoole\ORM\DbManager;
use EasySwoole\ORM\Exception\Exception;
use EasySwoole\Pool\Exception\PoolEmpty;
use EasySwoole\RedisPool\RedisPool;

class TheWorldTreeController extends UserBase
{


    # 世界树 接口  每天 11点运行
    function TheWorldTree()
    {
        # 获取所有的行号
        try {
            $task = \EasySwoole\EasySwoole\Task\TaskManager::getInstance();


            $task->async(new TheTreeFromWorldTask(['user' => 'custom']));


            $this->writeJson(200, [], "调用成功");
            return true;

        } catch (\Throwable $e) {
            $this->writeJson(-1, [], "TheWorldTree 执行异常:" . $e->getMessage());
            return false;
        }
    }
    # 获取当日世界树的 执行结果
    function getTodayTheWorldTree()
    {
        try {
            $data = [];
            $redis = RedisPool::defer('redis');
            $result = $redis->hGetAll(Date("Y-m-d", time()) . "_worldTree");
            if ($result) {
                foreach ($result as $k => $item) {
                    $data[$k] = json_decode($item);
                }
            }
            $return = [
                'total' => count($data),
                'data' => $data
            ];
            $this->writeJson(200, $return, "调用成功");
            return true;
        } catch (\Throwable $exception) {
            $this->writeJson(-1, [], "获取异常:" . $exception->getMessage());
        }
    }
    # 昨日一键收取
    function yesterdayGetOne()
    {
        try {
            $ids = $this->request()->getParsedBody('ids');
            $id_array = explode('@', $ids);
            foreach ($id_array as $item) {
                DbManager::getInstance()->invoke(function ($client) use ($item) {
                    $one = AccountNumberModel::invoke($client)->get(['id' => $item]);
                    if ($one) {
                        (new Tools())->OneKey($one['token_value'], $one['user_id'], $one['id']); #$token_value, $user_id, $account_number_id
                    }
                });
            }
        } catch (\Throwable $e) {
            $this->writeJson(-1, [], "yesterdayGetOne 执行异常:" . $e->getMessage());
            return false;
        }
    }
    #世界树浇水
    function yesterdayWatering()
    {
        try {
            $ids = $this->request()->getParsedBody('ids');
            $id_array = explode('@', $ids);
            foreach ($id_array as $item) {
                DbManager::getInstance()->invoke(function ($client) use ($item) {
                    $one = AccountNumberModel::invoke($client)->get(['id' => $item]);
                    if ($one) {
                        # 判断是否可以浇水
                        $redis = RedisPool::defer('redis');
                        $redis_data = $redis->hGet(Date("Y-m-d", time()) . "_worldTree", "account_" . $item);
                        $redis_array = json_decode($redis_data, true);
                        if ($redis_array['water'] == 0) {
                            # 说明今天没有浇过水
                            (new Tools())->Watering($one['token_value'], $one['user_id'], $one['id']); #$token_value, $user_id, $account_number_id
                        }
                    }
                });
            }
            $this->writeJson(200, [], "yesterdayGetOne 执行成功");
        } catch (\Throwable $e) {
            $this->writeJson(-1, [], "yesterdayGetOne 执行异常:" . $e->getMessage());
            return false;
        }
    }

}