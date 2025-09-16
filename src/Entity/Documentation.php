<?php

use App\Service\Auth\Keycloak;
use App\Service\JsonSchema\Validator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Process\Process;
use Ramsey\Uuid\Uuid;

function listFunction($auth)
{

    $resources = DB::query("SELECT * from resource_type");

    $controller_dir = __DIR__."/../Controller/";
    $files = array_diff(scandir($controller_dir), array('.', '..','.gitignore'));

    $controllers = array();
    foreach ($files as $f) {
        $controller_name = str_replace("Controller.php", "", $f);
        $controllers[$controller_name] = array('data' => array());
        $content = file_get_contents($controller_dir.$f);
        $categories = ['name','methods'];
        // $categories = ['name','methods','condition'];
        $lines = explode("\n", $content);
        foreach ($lines as $l) {
            $l = trim($l);
            $obj = array();
            if (strpos($l, '#[Route') > -1) {
                $l = substr($l, 8, -2);
                $test = json_decode($l);
                $row = explode(",", $l);

                foreach ($row as $r) {
                    $r = trim($r);
                    if (strpos($r, "'/") === 0) {
                        $url = str_replace("'", "", $row[0]);
                        $obj['url'] = $url;
                    } else {
                        foreach ($categories as $cat) {
                            if (strpos($r, $cat) === 0) {
                                $name = str_replace($cat, '"'.$cat.'"', $r);
                                $name = "{".str_replace("'", '"', $name)."}";
                                $tmp_name = json_decode($name, true);
                                if (isset($tmp_name[$cat])) {
                                    $obj[$cat] = $tmp_name[$cat];
                                }
                            }
                        }
                    }
                }
                $controllers[$controller_name]['data'][] = $obj;
            }

        }
    }

    return array('resources' => $resources,'files' => $files,'controllers' => $controllers);
}
