<?php

namespace App\Admin\Controllers;

use App\Exceptions\InvalidRequestException;
use App\Exceptions\SystemException;
use App\Http\Requests\Request;
use App\Models\Order;
use App\Http\Requests\Admin\HandleRefundRequest;

use App\Models\User;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use App\Http\Controllers\Controller;
use Encore\Admin\Controllers\ModelForm;

class OrdersController extends Controller
{
    use ModelForm;

    /**
     * Index interface.
     *
     * @return Content
     */
    public function index()
    {
        return Admin::content(function (Content $content) {

            $content->header('订单列表');
            $content->description('description');

            $content->body($this->grid());
        });
    }

    /**
     * Edit interface.
     *
     * @param $id
     * @return Content
     */
    public function edit($id)
    {
        return Admin::content(function (Content $content) use ($id) {

            $content->header('header');
            $content->description('description');

            $content->body($this->form()->edit($id));
        });
    }

    /**
     * Create interface.
     *
     * @return Content
     */
    public function create()
    {
        return Admin::content(function (Content $content) {

            $content->header('header');
            $content->description('description');

            $content->body($this->form());
        });
    }

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        $that = $this;
        return Admin::grid(Order::class, function (Grid $grid) use($that) {
            // 只展示已支付的订单，并且默认按支付时间倒序排序
            $grid->model()->whereNotNull('paid_at')->orderBy('paid_at', 'desc');

            $grid->no('订单流水号');
            // 展示关联关系的字段时，使用 column 方法
            $grid->user_id('买家')->display(function ($value) use ($that){
                return $that->getUserName($value);
            });
            $grid->total_amount('总金额')->sortable();
            $grid->paid_at('支付时间')->sortable();
            $grid->ship_status('物流')->display(function($value) {
                return Order::$shipStatusMap[$value];
            });
            $grid->refund_status('退款状态')->display(function($value) {
                return Order::$refundStatusMap[$value];
            });
            // 禁用创建按钮，后台不需要创建订单
            $grid->disableCreateButton();
            $grid->actions(function ($actions) {
                // 禁用删除和编辑按钮
                $actions->disableDelete();
                $actions->disableEdit();

                //$actions->append() 方法可以在每一行的 操作 那一栏添加 Html 代码，这里我们添加了一个 查看 按钮。
                $actions->append('<a class="btn btn-xs btn-primary" href="'.route('admin.orders.show', [$actions->getKey()]).'">查看</a>');

            });
            $grid->tools(function ($tools) {
                // 禁用批量删除按钮
                $tools->batch(function ($batch) {
                    $batch->disableDelete();
                });

            });
        });
    }

    //获取下单的用户名
    protected function getUserName($user_id)
    {
        return User::select(\DB::raw('name'))->find($user_id)->name;
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form()
    {
        return Admin::form(Order::class, function (Form $form) {

            $form->display('id', 'ID');

            $form->display('created_at', 'Created At');
            $form->display('updated_at', 'Updated At');
        });
    }

    //自定义方法---订单详情
    public function show(Order $order)
    {
        $that = $this;
        return Admin::content(function (Content $content) use ($order, $that){
            $content->header('查看订单');
            //body方法可以接受laravel的视图作为参数
            $username = $that->getUserName($order->user_id);
            $content->body(view('admin.orders.show', ['order'=>$order, 'username'=>$username]));
        });
    }

    public function ship(Order $order, Request $request)
    {
        //判断当前订单是否已支付
        if (!$order->paid_at) {
            throw new InvalidRequestException('该订单未付款');
        }
        //判断当前订单状态是否为未发货
        if ($order->ship_status !== Order::SHIP_STATUS_PENDING) {
            throw new InvalidRequestException('该订单已发货');
        }
        //Laravel 5.5之后validate方法可以返回校验过的值
        $data = $this->validate($request, [
            'express_company' => ['required'],
            'express_no' => ['required']
        ], [], [
            'express_company'=>'物流公司',
            'express_no'=>'物流单号'
        ]);
        //将订单发货状态改为已发货，并存入物流信息
        $order->update([
            'ship_status'=>Order::SHIP_STATUS_DELIVERED,
            //我们在order模型的$casts属性里面指明了ship_data是一个数组
            //因此这里可以直接吧数组传过去
            'ship_data'=>$data
        ]);

        //返回上一页
        return redirect()->back();
    }


    //后台拒绝退款
    public function handleRefund(Order $order, HandleRefundRequest $request)
    {
        // 判断订单状态是否正确
        if ($order->refund_status !== Order::REFUND_STATUS_APPLIED) {
            throw new InvalidRequestException('订单状态不正确');
        }
        // 是否同意退款
        if ($request->input('agree')) {
            $this->_refundOrder($order);
        } else {
            // 将拒绝退款理由放到订单的 extra 字段中
            $extra = $order->extra ?: [];
            $extra['refund_disagree_reason'] = $request->input('reason');
            // 将订单的退款状态改为未退款
            $order->update([
                'refund_status' => Order::REFUND_STATUS_PENDING,
                'extra'         => $extra,
            ]);
        }

        return $order;
    }


    protected function _refundOrder(Order $order)
    {
        //判断订单支付方式
        switch ($order->payment_method) {
            case 'wechat':
                $refundNo = Order::getAvailableRefundNo();
                app('wechat_pay')->refund([
                    'out_trade_no' =>$order->no, //之前的流水单号
                    'total_fee' => $order->total_amount * 100, //原订单金额，单位：分
                    'refund_fee' => $order->total_amount * 100, //本次要退款的金额，单位：分
                    'out_refund_no' => $refundNo, //退款单号
                    'notify_url' =>  route('payment.wechat.refund_notify'), //微信退款通知不是同步返回的，要加一个异步通知的回调地址
                ]);
                // 将订单状态改成退款中
                $order->update([
                    'refund_no' => $refundNo,
                    'refund_status' => Order::REFUND_STATUS_PROCESSING,
                ]);
                break;
            case 'alipay':
                $refundNo = Order::getAvailableRefundNo();
                //调用支付宝的refund方法
            $ret = app('alipay')->refund([
                'out_trade_no' => $order->no, //之前的订单流水号
                'refund_amount' => $order->total_amount, //退款金额，单位：元
                'out_request_no' => $refundNo, //退款订单号
            ]);
            //根据支付宝的文档，如果返回值里有sub_code字段说明退款失败
            if ($ret->sub_code) {
                //将退款失败的原因保存存入extra字段
                $extra = $order->extra;
                $extra['refund_failed_code'] = $ret->sub_code;
                //将订单的退款状态标记为退款失败
                $order->update([
                    'refund_no' => $refundNo,
                    'refund_status' => Order::REFUND_STATUS_FAILED,
                    'extra' => $extra,
                ]);
            } else {
                //将订单状态标记为退款成功并记录退款单号
                $order->update([
                    'refund_no' => $refundNo,
                    'refund_status' => Order::REFUND_STATUS_SUCCESS
                ]);
            }
            break;
            default:
                throw new SystemException('未知的订单支付方式'.$order->payment_method);
                break;
        }
    }



}
