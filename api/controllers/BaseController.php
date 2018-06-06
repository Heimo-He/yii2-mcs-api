<?php
/**
 * Created by PhpStorm.
 * User: heimo
 * Date: 2018/6/5
 * Time: 下午3:43
 */
namespace api\controllers;

use common\exceptions\ApiException;
use common\models\User;
use Yii;
use yii\filters\Cors;
use yii\helpers\ArrayHelper;
use yii\rest\ActiveController;
use yii\filters\auth\CompositeAuth;
use yii\filters\auth\QueryParamAuth;
use yii\web\Response;

class BaseController extends ActiveController
{
    public $isAuth = true;
    public $log;
    /**
     * @var yii/web/request.
     */
    public $request;
    /**
     * @var User
     */
    public $identity;
    public $response = [
        'code' => 20000,
        'data' => [],
        'message' => '请求成功',
    ];

    public function init()
    {
        $this->identity = Yii::$app->user->identity;
        $this->request = Yii::$app->request;
        parent::init(); // TODO: Change the autogenerated stub
    }

    public function actions() {
        $actions = parent::actions();
        // 禁用自带行为
        unset($actions['index'],$actions['delete'], $actions['create'], $actions['update'], $actions['view'], $actions['options']);

        return $actions;
    }

    public function behaviors()
    {
        $behaviors = parent::behaviors();

        unset($behaviors['authenticator']);

        //跨域处理
        if(isset(Yii::$app->params['cors'])){
            $behaviors['corsFilter'] = [
                'class' => Cors::class,
                //配置该类的cors属性
                'cors' => Yii::$app->params['cors']
            ];
        }

        // 需要用户验证
        if ($this->isAuth){
            $behaviors['authenticator'] = [
                'class' => CompositeAuth::className(),
                'authMethods' => [
                    QueryParamAuth::className(),
                ],
            ];
        }

        //后端回调验证
        if(isset(Yii::$app->params['callbackRoutes']) && in_array($this->route, Yii::$app->params['callbackRoutes'])){

            $callbackKey = Yii::$app->request->isGet ? Yii::$app->request->getQueryParam('key') : Yii::$app->request->getBodyParam('key');

            if(!$callbackKey || $callbackKey != Yii::$app->params['callbackKey']){
                throw new ApiException(403, 'invalid key');
            }

            $this->enableCsrfValidation = false;
        }elseif($this->isAuth){
            Yii::$app->user->login(User::findByUsername('root'));
        }

        //格式化响应为json
        $behaviors['contentNegotiator']['formats']['text/html'] = Response::FORMAT_JSON;
        return $behaviors;
    }

    /**
     * 判断响应数据是否已经规范化
     * @param $response
     * @return bool
     */
    public function isNormalized($response){
        return isset($response['code']) && isset($response['message']) && isset($response['data']);
    }

    /**
     * 规范响应数据的数据结构
     *
     * 此时响应码一般为200
     * @param $originResponse
     * 1. true
     * 2. null
     * 3. $this->response
     * 4. data
     * @return array 要输出给客户端的响应
     */
    public function normalizeResponse($originResponse){
        $responseMsg = $this->response;
        switch (Yii::$app->getRequest()->getMethod()){
            case 'GET':
            case 'POST' :
            case 'PUT' :
            case 'DELETE' :
                //主要是确定success的值，error及errorMsg由addError()来设置
                if(Yii::$app->response->statusCode == 200) {  //处理action直接返回的响应
                    if ($this->isNormalized($originResponse)) {   //是否为已经规范化的结构，即是否为$this->response的数据结构
                        //存在success字段表示该请求为批量操作，且为0时，表示全部失败，修改状态码
                        if (isset($originResponse['data']['success']) && $originResponse['data']['success'] === 0) {
                            $responseMsg['code'] = 40000;
                            Yii::$app->response->setStatusCode(400);
                        }
                    } else {
                        //单个操作成功的情况
                        if ($originResponse === true || $originResponse === null) {
                            $responseMsg['code'] = 20000;
                        } else {
                            $responseMsg['data'] = $originResponse;
                        }
                    }
                }elseif (Yii::$app->response->statusCode == 404){
                    $responseMsg['code'] = 40004;
                    $responseMsg['message'] = '页面未找到';
                }else{  //处理throw抛出的错误信息
                    $responseMsg['data']['success'] = 0;
                }

                $response = $responseMsg;
                return $response;
                break;
        }

        return $originResponse;
    }

    /**
     * 写入日志
     * @param $response
     */
    public function writeLog($response){
        if($this->log && $this->log->name){
            //记录返回值
            $content = '';

            if (Yii::$app->response->statusCode != 200){
                $errorMsg = ArrayHelper::getValue($response, 'data.errorMsg', []);

                foreach ($errorMsg as $errMsg){
                    $content .= " {$errMsg['message']} : {$errMsg['where']}; <br>";
                }

            }
        }
    }

    public function afterAction($action, $result)
    {
        if(Yii::$app->params['Authorization'] && $this->identity && Yii::$app->cache->get('access-token_' . $this->identity->access_token) - time() <= 600){
            //更新access_token
            $this->identity->generateAccessToken();
            Yii::$app->response->headers['Authorization-Access-Token'] = $this->identity->access_token;
        }

        $response = parent::afterAction($action, $result);
        $response = $this->normalizeResponse($response);

        $this->writeLog($response);

        return $response;
    }

    /**
     * 主要用于拦截action中抛出的ApiException异常，以便规范化数据结构
     * @param string $id
     * @param array $params
     * @return mixed
     * @throws ApiException
     */
    public function runAction($id, $params = []){
        try{
            return parent::runAction($id, $params);
        }catch (ApiException $e){
            $errors = $e->data;
            if(!isset($errors['errorMsg'])){
                $errors = $e->data;
                if(!isset($errors['errorMsg'])){
                    unset($this->response['data']['success'], $this->response['data']['error']);
                    $this->response['message'] =$e->data['message'];
                    $this->response['data'] =$e->data['data'];
                    $this->response['code'] = $e->apiCode;
                    $e->data = $this->normalizeResponse($this->response);
                    $this->writeLog($this->response);
                    header("Access-Control-Allow-Origin: " . implode(',', \Yii::$app->params['cors']['Origin']));
                    header('Access-Control-Allow-Headers: ' . implode(',', \Yii::$app->params['cors']['Access-Control-Request-Headers']));
                    header('Access-Control-Allow-Methods: ' . implode(',', \Yii::$app->params['cors']['Access-Control-Request-Method']));
                }
                throw $e;
            }
            throw $e;
        }
    }
}