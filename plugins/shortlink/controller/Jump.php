<?php
namespace plugin\shortlink\controller;

use plugin\shortlink\service\VisitService;
use plugin\FrontController;

class Jump extends FrontController
{
    protected $noNeedLogin = ['*'];
    protected $noNeedAuth = ['*'];

    public function redirect($code = '')
    {
        $code = $code ?: $this->request->param('code');
        $password = $this->request->param('password');

        $visitService = new VisitService();
        $result = $visitService->visit($code, $password);

        if (!$result['success']) {
            if (!empty($result['need_password'])) {
                return $this->passwordForm($code);
            }
            return $this->showError($result['message']);
        }

        $statusCode = shortlink_config('redirect_type', '302') === '301' ? 301 : 302;
        return redirect($result['url'], $statusCode);
    }

    protected function passwordForm($code)
    {
        $code = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
        return response('<div class="container mt-5"><div class="row justify-content-center"><div class="col-md-6"><div class="card"><div class="card-header">访问密码</div><div class="card-body"><form method="get" action="/s/' . $code . '"><div class="mb-3"><input type="password" name="password" class="form-control" placeholder="请输入访问密码" required></div><button type="submit" class="btn btn-primary">进入</button></form></div></div></div></div></div>');
    }

    protected function showError($message)
    {
        return response('<div class="container mt-5"><div class="row justify-content-center"><div class="col-md-6"><div class="card border-danger"><div class="card-header text-danger">链接失效</div><div class="card-body"><p>' . htmlspecialchars($message) . '</p><a href="/" class="btn btn-sm btn-outline-primary">返回首页</a></div></div></div></div></div>');
    }
}
