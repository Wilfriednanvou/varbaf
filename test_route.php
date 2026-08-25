<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

$req = Illuminate\Http\Request::create('admin/caisses/1/brouillard/1', 'GET');
\Illuminate\Support\Facades\Auth::loginUsingId(1);
$res = $kernel->handle($req);

echo "STATUS: " . $res->getStatusCode() . "\n";
if ($res->getStatusCode() != 200) {
    if (method_exists($res, 'exception') && $res->exception) {
        echo $res->exception->getMessage();
        echo "\n";
        echo $res->exception->getTraceAsString();
    }
}
