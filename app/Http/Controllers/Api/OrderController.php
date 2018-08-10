<?php

namespace App\Http\Controllers\Api;

use App\Models\Address;
use App\Models\Cart;
use App\Models\Member;
use App\Models\Menu;
use App\Models\Order;
use App\Models\OrderGood;
use App\Models\Shop;
use App\Models\User;
use App\Mail\OrderShipped;
use EasyWeChat\Foundation\Application;
use Illuminate\Database\QueryException;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\LabelAlignment;
use Endroid\QrCode\QrCode;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Mrgoon\AliSms\AliSms;

class OrderController extends BaseController
{
    /**
     * 添加订单，订单商品
     * @param Request $request
     * @return array
     */
    public function add(Request $request)
    {
        //得到收货地址
        $address = Address::find($request->post('address_id'));
        //判断地址
        if ($address === null) {
            return [
                'status' => 'false',
                'message' => '地址选择错误'
            ];
        }
        //得到用户id
        $data['user_id'] = $request->post('user_id');
        //得到购物车信息
        $carts = Cart::where('user_id', $request->post('user_id'))->get();
        //通过购物车的第一条数据的商品ID在菜品中找到shop_id
        $shopId = Menu::find($carts[0]->goods_id)->shop_id;
        //得到店铺id
        $data['shop_id'] = $shopId;
        //生成订单编号
        $data['sn'] = date('ymdHis') . rand(1000, 9999);
        //得到地址
        $data['provence'] = $address->provence;
        $data['city'] = $address->city;
        $data['area'] = $address->area;
        $data['detail_address'] = $address->detail_address;
        $data['tel'] = $address->tel;
        $data['name'] = $address->name;
        //定义总价格
        $total = 0;
        foreach ($carts as $k => $v) {
            $menu = Menu::where('id', $v->goods_id)->first();
            $total += $v->amount * $menu->goods_price;
        }
        //得到总价格
        $data['total'] = $total;
        //设置状态为代付款
        $data['status'] = 0;
        //事务启动
        DB::beginTransaction();
        try {
            //插入订单数据
            $order = Order::create($data);
            //添加订单商品
            foreach ($carts as $k1 => $v1) {
                //得到当前菜品
                $menu = Menu::find($v1->goods_id);
                //给各字段赋值
                $dataGoods['order_id'] = $order->id;
                $dataGoods['goods_id'] = $v1->goods_id;
                $dataGoods['amount'] = $v1->amount;
                $dataGoods['goods_name'] = $menu->goods_name;
                $dataGoods['goods_img'] = $menu->goods_img;
                $dataGoods['goods_price'] = $menu->goods_price;
                //插入订单商品数据
                OrderGood::create($dataGoods);
            }
            //清空购物车
            Cart::where('user_id', $request->post('user_id'))->delete();
            //提交
            DB::commit();
        } catch (QueryException $exception) {
            //回滚
            DB::rollBack();
            //返回数据
            return [
                "status" => "false",
                "message" => $exception->getMessage()
            ];
        }
        /*$user = User::where('shop_id', $order->shop_id)->first();
        //通过审核发送邮件
        Mail::to($user)->send(new OrderShipped($order));*/
        return [
            'status' => 'true',
            'message' => '订单，订单商品添加成功',
            'order_id' => $order->id
        ];
    }

    /**
     * 订单列表
     * @param Request $request
     * @return array
     */
    public function list(Request $request)
    {
        //得到所有订单信息
        $orders = Order::where('user_id', $request->input('user_id'))->get();
        //声明一个空数组
        $datas = [];
        //循环取出数据
        foreach ($orders as $order) {
            $data['id'] = $order->id;
            $data['order_code'] = $order->sn;
            $data['order_birth_time'] = (string)$order->created_at;
            $data['order_status'] = $order->order_status;
            $data['shop_id'] = (string)$order->shop_id;
            $data['shop_name'] = $order->shop->shop_name;
            $data['shop_img'] = $order->shop->shop_img;
            $data['order_price'] = $order->total;
            $data['order_address'] = $order->provence . $order->city . $order->area . $order->detail_address;
            $data['goods_list'] = $order->goods;
            $datas[] = $data;
        }
        //返回数据
        return $datas;
    }

    /**
     * 订单详情
     * @param Request $request
     * @return mixed
     */
    public function detail(Request $request)
    {
        //得到当前订单
        $order = Order::find($request->input('id'));
        //得到各字段的值
        $data['id'] = $order->id;
        $data['order_code'] = $order->sn;
        $data['order_birth_time'] = (string)$order->created_at;
        $data['order_status'] = $order->order_status;
        $data['shop_id'] = (string)$order->shop_id;
        $data['shop_name'] = $order->shop->shop_name;
        $data['shop_img'] = $order->shop->shop_img;
        $data['order_price'] = $order->total;
        $data['order_address'] = $order->provence . $order->city . $order->area . $order->detail_address;
        $data['goods_list'] = $order->goods;
        //返回数据
        return $data;
    }

    /**
     * 支付金额
     * @param Request $request
     * @return array
     */
    public function pay(Request $request)
    {
        //得到当前订单信息
        $order = Order::find($request->post('id'));
        //找到当前用户
        $member = Member::where('id', $order->user_id)->first();
        //判断余额
        if ($order->total > $member->money) {
            return [
                'status' => 'false',
                'message' => '您的余额已不足，请充值'
            ];
        }
        //扣钱
        $member->money = $member->money - $order->total;
        $member->jifen += 5;
        $member->save();
        //更改订单状态
        $order->status = 1;
        $order->save();
        //配置 发短信
        $config = [
            'access_key' => 'LTAIZOaBhGHVz35m',
            'access_secret' => 'cGqV0fITIAIm7l1giOl2nQsaGoRqaD',
            'sign_name' => '蒋春容',
        ];
        $aliSms = new AliSms();
        $aliSms->sendSms($member->tel, 'SMS_141670132', ['product' => $order->sn], $config);
        //dd($response);
        return [
            'status' => 'true',
            'message' => '支付成功'
        ];
    }

    /**
     * 微信支付
     * @param Request $request
     * @throws \Endroid\QrCode\Exception\InvalidPathException
     * @throws \Endroid\QrCode\Exception\InvalidWriterException
     */
    public function wxPay(Request $request)
    {
        //得到订单
        $order = Order::find($request->input('id'));
        $shop = Shop::where('id', $order->shop_id)->first();
        //dd(config('wechat'));
        //1.创建操作微信的对象
        $app = new Application(config('wechat'));
        //2.得到支付对象
        $payment = $app->payment;
        //3.生成订单
        //3.1 订单配置
        $attributes = [
            'trade_type' => 'NATIVE', // JSAPI，NATIVE，APP...
            'body' => $shop->shop_name,
            'detail' => $shop->shop_name.'详情',
            'out_trade_no' => $order->sn,
            'total_fee' => $order->total * 100, // 单位：分
            'notify_url' => 'http://elemwww.jiangchunrong.cn/api/order/ok', // 支付结果通知网址，如果不设置则会使用配置里的默认地址
            // 'openid' => '当前用户的 openid', // trade_type=JSAPI，此参数必传，用户在商户appid下的唯一标识，
        ];
        //3.2 订单生成
        $order = new \EasyWeChat\Payment\Order($attributes);
        //4.统一下单
        $result = $payment->prepare($order);
        // dd($result);
        if ($result->return_code == 'SUCCESS' && $result->result_code == 'SUCCESS') {
            //5.取出预支付链接
            $payUrl = $result->code_url;
            //6.把支付链接生成二维码
            /*$qrCode = new QrCode($payUrl);
            header('Content-Type: '.$qrCode->getContentType());
            echo $qrCode->writeString();*/

            //Create a basic QR code
            $qrCode = new QrCode($payUrl);//地址
            $qrCode->setSize(200);//二维码大小

            //Set advanced options
            $qrCode->setWriterByName('jpeg');
            $qrCode->setMargin(10);
            $qrCode->setEncoding('UTF-8');
            $qrCode->setErrorCorrectionLevel(ErrorCorrectionLevel::HIGH);//容错级别
            $qrCode->setForegroundColor(['r' => 0, 'g' => 0, 'b' => 0, 'a' => 0]);
            $qrCode->setBackgroundColor(['r' => 255, 'g' => 255, 'b' => 255, 'a' => 0]);
            $qrCode->setLabel('微信扫码支付', 16, public_path() . '/assets/noto_sans.otf', LabelAlignment::CENTER);
            $qrCode->setLogoPath(public_path() . '/' . $shop->shop_img);
            $qrCode->setLogoWidth(80);//logo大小

            //Directly output the QR code
            header('Content-Type: ' . $qrCode->getContentType());
            echo $qrCode->writeString();
            exit;
        }
    }

    /**
     * 微信异步通知
     * @return \Symfony\Component\HttpFoundation\Response
     * @throws \EasyWeChat\Core\Exceptions\FaultException
     */
    public function ok()
    {
        //1.创建操作微信的对象
        $app = new Application(config('wechat'));
        //2.处理微信通知信息
        $response = $app->payment->handleNotify(function ($notify, $successful) {
            // 使用通知里的 "微信支付订单号" 或者 "商户订单号" 去自己的数据库找到订单
            //  $order = 查询订单($notify->out_trade_no);
            $order = Order::where("sn", $notify->out_trade_no)->first();

            if (!$order) { // 如果订单不存在
                return 'Order not exist.'; // 告诉微信，我已经处理完了，订单没找到，别再通知我了
            }
            // 如果订单存在
            // 检查订单是否已经更新过支付状态
            if ($order->status !== 0) { // 假设订单字段“支付时间”不为空代表已经支付
                return true; // 已经支付成功了就不再更新了
            }
            // 用户是否支付成功
            if ($successful) {
                // 不是已经支付状态则修改为已经支付状态
                // $order->paid_at = time(); // 更新支付时间为当前时间
                $order->status = 1;//更新订单状态
            }
            $order->save(); // 保存订单
            return true; // 返回处理完成
        });
        return $response;
    }

    /**
     * 订单状态
     * @param Request $request
     * @return array
     */
    public function status(Request $request)
    {
        return [
            'status' => Order::find($request->input('id'))->status
        ];
    }
}
