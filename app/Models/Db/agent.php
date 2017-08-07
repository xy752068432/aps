<?php

namespace App\Models\Db;

class Agent extends Model
{
    public static $model = 'agent';
    public static function get($arr)
    {
        return app('db')->table(self::$model)
                        ->where($arr['where'])
                        ->first();
    }

    public static function add($AgentArr)
    {
        return app('db')->table(self::$model)
                        ->insert($AgentArr);
    }
    public static function update($arr)
    {
        return app('db')->table(self::$model)
                        ->where($arr['where'])
                        ->update($arr['update']);
    }
    public static function mget($arr)
    {
        return app('db')->table(self::$model)
                        ->where(isset($arr['where']) ? $arr['where'] : [])
                        ->select('id', 'username')
                        ->get();
    }
}

?>