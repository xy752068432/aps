<?php

namespace App\Models;

use DB;
use App\Exceptions\ApiException;
use App\Models\Db\User as DbUser;
use ReflectionClass;

class User extends Model
{

    public static $model = 'User';

    public function mget()
    {
        return DbUser::mget();
    }

    public function get($id)
    {
        $this->primaryValue = $id;
        return $this;
    }


    /**
     * [login description]
     * 用户登录
     * @author cg
     * @param  [array] $userArr [username,passwd,last_ip]
     * @return [array]          [[id => '用户id',token => '安全token']]
     */
    public function login($userArr)
    {
        $user = DbUser::get(['where' => ['username' => $userArr['username']]]);
        if (empty($user)) {
            throw new ApiException("此用户不存在", 2);
        }
        // 密码不正确
        if (!password_verify($userArr['passwd'], $user->passwd)) {
            return [];
        }
        $token = genToken();
        $lastLoginTime = time();
        DbUser::updateById($user->id, [
            'last_login_time' => $lastLoginTime,
            'login_count' => $user->login_count + 1,
            'token' => $token,
            'token_expired' => $lastLoginTime + 3600 * 24,
            'user_agent' => $userArr['user_agent'],
            'last_ip' => $userArr['last_ip'],
            ]
        );
        return ['id' => $user->id, 'token' => $token];
    }
    public function loginBy3($userInfo3)
    {
        $user = DbUser::get(['where' => ['openid' => $userInfo3['openid'] ] ]);
        $lastIp = getIp();
        $token = genToken();
        $lastLoginTime = time();
        if (empty($user)) {
            $arr = [
                    'last_login_time' => $lastLoginTime,
                    'login_count' => 1,
                    'token' => $token,
                    'token_expired' => $lastLoginTime + 3600 * 24,
                    'user_agent' => $userInfo3['User-Agent'],
                    'last_ip' => $lastIp,
                    'openid' => $userInfo3['openid'],
                    'headimgurl' => $userInfo3['headimgurl'],
                    'access_token' => $userInfo3['access_token'],
                    'refresh_token' => $userInfo3['refresh_token'],
                    'code' => $userInfo3['code'],
                ];
            if(DbUser::add($arr)) {
                return DbUser::get(['where' => ['openid' => $userInfo3['openid'] ] ]);
            } else {
                throw new ApiException('用户信息插入失败', config('err.insert_user_arr_err.code') );
            }
        } else {
            $arr = [
                'last_login_time' => $lastLoginTime,
                'login_count' => $user->login_count + 1,
                'token' => $token,
                'token_expired' => $lastLoginTime + 3600 * 24,
                'user_agent' => $userInfo3['User-Agent'],
                'last_ip' => $lastIp,
                'headimgurl' => $userInfo3['headimgurl'],
                'access_token' => $userInfo3['access_token'],
                'refresh_token' => $userInfo3['refresh_token'],
                'code' => $userInfo3['code'],
            ];
            if(DbUser::updateByOpenid($user->openid, $arr)) {
                return DbUser::get(['where' => ['openid' => $userInfo3['openid'] ] ]);
            } else {
                throw new ApiException('用户信息更新失败', config('err.insert_user_arr_err.code') );
            }
        }
    }
    public function hasCode($code)
    {
        $user = DbUser::get(['where' => ['code' => $code]]);
        if (empty($user)) {
            return false;
        }
        return $user;
    }
}
