<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Order;
use App\Models\GoodsCar;
use App\Models\Goods;
use App\Models\Address;
use App\Models\Area;
use App\Models\Province;
use App\Models\City;
use App\Models\Coupon;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Models\Agent;
use App\Models\Payment;
use App\Models\WxPay;

class OrderController extends Controller
{
    /**
     * [展示临时订单]
     * @param  Request $request [Request实例]
     * @return [Array]           [返回包含临时订单的信息]
     */

    public function preOrder(Request $request, $user)
    {
        $rules = [
            'addr_id' => 'integer',
            'goods_car_ids' => 'required|string',
        ];
        $this->valIdate($request, $rules);
        $goodsCarIds = explode(',', $request->input('goods_car_ids'));
        array_pop($goodsCarIds);
        $addrId = $request->input('addr_id', null);
        $orderModel = new Order();
        $addressModel = new Address();
        $goodsCarModel = new GoodsCar();
        // 获取购物车的信息,该返回的数据为对象数组
        $goodsCars = $goodsCarModel->mgetByGoodsCarIds($user->id, $goodsCarIds);
        $this->checkGoodsCarWork($goodsCars, $goodsCarIds);
        if (($addrDetail = $this->hasAddr($user->id, $addrId)) === false) {
            return config('error.addr_not_exist');
        }
        return [
            'rcv_info' => $addressModel->getFullAddr($addrDetail),
            'orders_info' => $orderModel->getPrice($goodsCars),
        ];
    }
    /**
     * [展示某一个订单]
     * @param  Request $request [Request实例]
     * @return [Array]           [返回订单有关的信息]
     */
    public function show(Request $request, $user, $orderId)
    {
        $addressModel = new Address();
        $orderModel = new Order();
        $order = $orderModel->get($user->id, $orderId);
        $rsp = config('error.success');
        if(!$orderModel->isExist($order)) {
            return config('error.order_not_exist');
        }
        if (empty(($addrDetail = $addressModel->get($user->id, $order->addr_id)))) {
            return config('error.addr_not_exist');
        }
        $rsp = $orderModel->getOrderInfo($order);
        $rsp['addr_info'] = $addressModel->getFullAddr($addrDetail);
        return $rsp;
    }

    /**
     * [创建订单]
     * @param  Request $request [Request实例]
     * @return [type]           [description]
     */
    public function store(Request $request, $user)
    {
        $rules = [
            'pay_id' => 'required|integer',
            'addr_id' => 'integer',
            'goods_car_ids' => 'required|string',
            'agent_id' => 'integer',
        ];
        $this->valIdate($request, $rules);
        $goodsCarIds = explode(',', $request->input('goods_car_ids'));
        array_pop($goodsCarIds);
        $payId = $request->input('pay_id');
        $addrId = $request->input('addr_id', null);
        $couponId = $request->input('coupon_id', null);
        $agentId = $request->input('from_agent_id', null);
        $orderModel = new Order();
        $addressModel = new Address();
        $goodsModel = new Goods();
        $goodsCarModel = new GoodsCar();
        $couponModel = new Coupon();
        if (($addr = $this->hasAddr($user->id, $addrId)) === false) {
            return config('error.addr_null_err');
        }
        // 获取购物车的信息,该返回的数据为对象数组
        $goodsCars = $goodsCarModel->mgetByGoodsCarIdsWithStatus($user->id, $goodsCarIds);
        $coupon = $this->checkOrderArgs($goodsCars, $agentId, $payId, $goodsCarIds, $couponId);
        try {
            app('db')->beginTransaction();
            // 更新购物车的状态
            $goodsCarModel->updateStatus($user->id, $goodsCarIds, 1);
            //更新商品的库存
            $goodsMap = array_column(obj2arr($goodsCars), 'goods_num', 'goods_id');
            $goodsModel->modifyStock($goodsMap, 'decrement');
            $couponGoodsId = $coupon['goods_id'];
            //更新优惠券使用次数
            if(!empty($coupon)) {
               $couponModel->modifyTimesById($couponId, $goodsMap[$couponGoodsId]);
            }
            //创建订单
            $time = time();
            $orders = [];
            $combinePayId = Order::getCombinePayId($user->id, $payId);
            $agentId = is_null($agentId) ? 0 : $agentId;
            foreach ($goodsCars as $goodsCar) {
                $orderNum = $this->getOrderNum(16);
                $orders[] = [
                    'order_num' => $orderNum,
                    'pay_id' => $payId,
                    'addr_id' => $addr->id,
                    'send_time' => mktime(0, 0, 0, date('m'), date('d') + 1, date('Y')),
                    'time_space' => 3,
                    'send_price' => 0,
                    'coupon_id' => !empty($coupon) && $coupon['goods_id'] == $goodsCar->goods_id ? $couponId : null,
                    'pay_status' => 1,
                    'order_status' => 1,
                    'user_id' => $user->id,
                    'created_at' => $time,
                    'goods_id' => $goodsCar->goods_id,
                    'goods_num' => $goodsCar->goods_num,
                    'combine_pay_id' => $combinePayId,
                    'agent_id' => $agentId,
                ];
            }
            $orderModel->create($orders);
            app('db')->commit();
        } catch(Exceptions $e) {
            app('db')->rollBack();
        }
        //创建订单完成,跳转到支付
        return  $this->choosePay($payId, $combinePayId, $orders, $user->openid);
    }
    /**
     * [判断地址是否存在]
     * @param  [type]  $userId [description]
     * @param  [type]  $addrId [description]
     * @return boolean         [description]
     */
    protected function hasAddr($userId, $addrId)
    {
       return (new Address())->isExist($userId, $addrId);
    }
    /**
     * [生成订单号]
     * @param  [type] $len [description]
     * @return [type]      [description]
     */
    private function getOrderNum($len)
    {
        return getRandomString($len);
    }
    private function checkPayEnable($payId)
    {
        $paymentModel = new Payment();
        if (!$paymentModel->payEnable($payId)) {
            throw new ApiException(config('error.pay_not_work_exception.msg'), config('error.pay_not_work_exception.code'));
        }
    }
    private function checkCouponWork($couponId, $goodsIds, $goodsCarMap)
    {
        //检查优惠码是否有效
        $coupon = null;
        $couponModel = new Coupon();
        if (!is_null($couponId)) {
            if (!($coupon = $couponModel->checkWork($couponId, 'id', $goodsIds, $goodsCarMap))) {
            throw new ApiException(config('error.not_work_coupon_exception.msg'), config('error.not_work_coupon_exception.code'));
            }
        }
        return $coupon;
    }
    private function checkOrderArgs($goodsCars, $agentId, $payId, $goodsCarIds, $couponId)
    {
        $couponModel = new Coupon();
        $agentModel = new Agent();
        $goodsModel = new Goods();
         //检查购物车信息是否正常
        $this->checkGoodsCarWork($goodsCars, $goodsCarIds);
         //检查是否能够支付
        $this->checkPayEnable($payId);
         //检查优惠码是否可用
        $goodsCarMap = getMap($goodsCars, 'goods_id');
        $goodsIds = array_column(obj2arr($goodsCars), 'goods_id');
        $coupon = $this->checkCouponWork($couponId, $goodsIds, $goodsCarMap);
        //检查代理是否存在
        if (!is_null($agentId)) {
            if (!$agentModel->has($agentId)) {
                throw new ApiException(config('error.not_work_agent_exception.msg'), config('error.not_work_agent_exception.code'));
            }
        }
        //判断购物车是否有过期商品或商品库存是否足够
        if (($abnormal = $goodsModel->isAbnormal($goodsCars)) !== false) {
            throw new ApiException($abnormal['msg'], $abnormal['code']);
        }

        return $coupon;
    }
    private function checkGoodsCarWork($goodsCars, $goodsCarIds)
    {
        if (count(obj2arr($goodsCars)) != count($goodsCarIds)) {
            throw new ApiException(config('error.goods_exception.msg'), config('error.goods_exception.code'));
        }
        return true;
    }
    protected function choosePay($payId, $combinePayId, $orders, $openid)
    {
        switch ($payId) {
            case '1':
                break;
            case '2':
                break;
            case '3':
                break;
            case '4':
                //检查版本是否支持支付
                $userAgent = $_SERVER['HTTP_USER_AGENT'];
                preg_match('/[a-zA-Z]+?\/(\d+\.\d+)/i', $userAgent, $matchs);
                if (floatval($matchs[1]) < 5.0) {
                    throw new ApiException(config('error.wx_version_low.msg'), config('error.wx_version_low.code'));
                }
                return (new WxPay())->pay($combinePayId, $orders, $openid);
                break;
            case '5':
                break;
        }
    }
    /**
     * [合并支付]
     * @param  Request $request [description]
     * @param  [type]  $user    [description]
     * @return [type]           [description]
     */
    public function combinePay(Request $request, $user)
    {
        $rules = [
            'order_ids' => 'required|string',
            'pay_id' => 'required|integer',
        ];
        $this->valIdate($request, $rules);
        $orderModel = new Order();
        $paymentModel = new Payment();
        $orderIds = explode(',', $request->input('order_ids'));
        array_pop($orderIds);
        $payId = $request->input('pay_id');
        $this->checkPayEnable($payId);
        $orders = $orderModel->mgetPayOrderByIds($user->id, $orderIds);
        //检查是否有无效的订单
        if (count(obj2arr($orders)) != count($orderIds)) {
            throw new ApiException(config('error.contain_order_not_work_exception.msg'), config('error.contain_order_not_work_exception.code'));
        }
        $combinePayId = Order::getCombinePayId($user->id, $payId);
        try {
            app('db')->beginTransaction();
            $orderModel->modifyCombinePayId($orderIds, $combinePayId);
            $rsp = $this->choosePay($payId, $combinePayId, $orders, $user->openid);
            app('db')->commit();
            return $rsp;
        } catch(Exceptions $e) {
            app('db')->rollBack();
        }
    }
    /**
     * [删除订单]
     * @param  Request $request [Request实例]
     * @return [Integer]           [0表示成功1表示失败]
     */
    public function delete(Request $request, $user, $orderId)
    {
        $rsp = config('error.success');
        $orderModel = new Order();
        $order = $orderModel->get($user->id, $orderId);
        if (!$orderModel->canDelete($order)) {
            return config('error.order_rm_fail');
        }
        $orderModel->remove($user->id, $orderId);
        return $rsp;
    }
    /**
     * [获取分类订单]
     * @param  Request $request [description]
     * @return [type]           [description]
     */
    public function index(Request $request, $user)
    {
        $statuses = ['全部', '待付款', '待发货', '待收货'];
        $rules = [
            'limit' => 'integer|max:10|min:1',
            'page' => 'integer|min:1',
            'status' => 'integer|max:4|min:0'
        ];
        $this->valIdate($request, $rules);
        $rsp = config('error.items');
        $status = $request->input('status', 0);
        $limit = $request->input('limit', 10);
        $page = $request->input('page', 1);
        $orderModel = new Order();
        $orders = $orderModel->mget($user->id, $limit, $page, $status);
        foreach ($statuses as $key => $value) {
            $rsp['actives'][] = ['index' => $key, 'actived' => $status == $key ? true : false ];
        }
        if (empty(obj2arr($orders))) {
            $rsp['code'] = 0;
            $rsp['items'] = [];
            $rsp['num'] = 0;
            $rsp['msg'] = '您还没有此类型订单';
        } else {
            $rsp['code'] = 0;
            $rsp['items'] = $orderModel->getOrdersInfo($orders);
            $rsp['num'] = count($rsp['items']);
        }
        return $rsp;
    }
    /**
     * [更新订单状态为等待发货]
     * @param  Request $request [description]
     * @return [type]           [description]
     */
    protected function waitSend(array $orderIds, $payTime, $transactionId)
    {
        (new Order())->mModify($orderIds, ['order_status' => 2, 'pay_status' => 2,'pay_time' => $payTime, 'transaction_id' => $transactionId]);
    }
    /**
     * [完成收货]
     * @param  Request $request [description]
     * @return [type]           [description]
     */
    public function finishRecv(Request $request, $user, $orderId)
    {
        $rsp = config('error.success');
        $orderModel = new Order();
        //获取订单
        $order = $orderModel->get($user->id, $orderId);
        if (!$orderModel->canFinish($order)) {
            return config('error.order_cannot_finish');
        }
        //根据该该订单的物流单号更新所有有关该物流的订单
        $orderModel->updateByLogstics($order->logistics_code, ['order_status' => 4]);
        return $rsp;
    }
    /**
     * [取消订单]
     * @param  Request $request [description]
     * @return [type]           [description]
     */
    public function cancel(Request $request, $user, $orderId)
    {
        $orderModel = new Order();
        $goodsModel = new Goods();
        $order = $orderModel->get($user->id, $orderId);
        $rsp = config('error.success');
        if (!$orderModel->cancelable($order)) {
            return config('error.order_no_cancel');
        }
        try {
                app('db')->beginTransaction();
                //更新库存
                $goodsModel->modifyStock([$order->goods_id => $order->goods_num]);
                //更新订单状态
                $orderModel->modifyByUser($orderId, $user->id, ['order_status' => 5]);
                app('db')->commit();
            } catch(Exceptions $e) {
                app('db')->rollBack();
            }
        return $rsp;
    }
    public function getTypeCount($user)
    {
        $orderModel = new Order();
        return $orderModel->getTypeCount($user->id);
    }
    public function recive()
    {
        $notify = file_get_contents('php://input');
        $notifyObj = obj2arr(simplexml_load_string($notify, 'SimpleXMLElement', LIBXML_NOCDATA));
        if (array_key_exists("return_code", $notifyObj) &&  $notifyObj['return_code'] != 'SUCCESS') {
            $this->reply('Fail', $notifyObj['return_msg']);
            return ;
        }
        if (array_key_exists("result_code", $notifyObj) &&  $notifyObj['result_code'] != 'SUCCESS') {
            $this->reply('Fail', $notifyObj['err_code_des']);
            return ;
        }
        $orderModel = new Order();
        $goodsModel = new Goods();
        $payModel = new WxPay();
        $goodsIds = $orderIds = [];
        $combinePayId = $notifyObj['out_trade_no'];
        $orders = $orderModel->mgetByCombinePayId($combinePayId);
        foreach ($orders as $order) {
            $goodsIds[] = $order->goods_id;
            $orderIds[] = $order->id;
            $transactionId = $order->transaction_id;
        }
        //若是已经处理,直接发送成功的信息
        if ($transactionId == $notifyObj['transaction_id']) {
            $this->reply('SUCCESS', 'OK');
            return ;
        }
        $params = $notifyObj;
        unset($params['sign']);
        $sign = $payModel->getSign($params);
        //检查签名sign
        if ($sign !== $notifyObj['sign']) {
            $this->reply('Fail', 'sign不一致');
            return ;
        }
        $goodsIds = array_unique($goodsIds);
        $goodses = $goodsModel->mgetByIds($goodsIds);
        if (count(obj2arr($goodses)) != count($goodsIds)) {
            throw new ApiException(config('error.goods_info_exception.msg'), config('error.goods_info_exception.code'));
        }
        //获取总金额
        $all = $payModel->getAll($goodses, obj2arr($orders));
        $all = $all * 100;
        if (strcmp($all, $notifyObj['total_fee']) !== 0) {
            $this->reply('Fail', '订单金额不一致');
            return;
        }
        $payTime = $notifyObj['time_end'];
        $transactionId = $notifyObj['transaction_id'];
        $this->waitSend($orderIds, $payTime, $transactionId);
        $this->reply('SUCCESS', 'OK');
    }

    protected function reply($status, $msg)
    {
        $replyInfo = <<<DATA
        <xml>
            <return_code><![CDATA[%s]]></return_code>
            <return_msg><![CDATA[%s]]></return_msg>
        </xml>
DATA;
        echo sprintf($replyInfo, $status, $msg);
    }
}
?>