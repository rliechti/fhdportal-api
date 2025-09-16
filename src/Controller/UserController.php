<?php

namespace App\Controller;

use App\Service\Auth\Keycloak;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Routing\Attribute\Route;
use OpenApi\Attributes as OA;
use Exception;

#[Route('/api')]
class UserController extends AbstractController
{
    #[OA\Get(
        path: "/api/users",
        summary: "Get Users (access restricted to admin)",
        tags: ['Users'],
        responses: [
            new OA\Response(
                response: 200,
                description: "Successful response",
                content: new OA\JsonContent(
                    type: "object",
                    properties: [
                        new OA\Property(property: "id", type: "string"),
                        new OA\Property(property: "username", type: "string"),
                        new OA\Property(property: "enabled", type: "boolean"),
                        new OA\Property(property: "firstName", type: "string"),
                        new OA\Property(property: "lastName", type: "string"),
                        new OA\Property(property: "email", type: "string"),
                        new OA\Property(property: "roles", type: "array")
                    ]
                )
            )
        ]
    )]
    #[Route('/users', name: 'get_users', methods: ['GET'])]
    public function getUsers(Request $request, Keycloak $auth, SerializerInterface $serializer): JsonResponse
    {
        if ($auth->isGuest()) {
            return new JsonResponse(['message' => 'Unauthorized'], status: 401);
        }
        $email =  $request->query->get('email');
        require __DIR__."/../Entity/Users.php";
        $users = getUsers($email);
        $content = $serializer->serialize($users, 'json');
        return new JsonResponse($content, json: true);
    }
    #[OA\Post(
        path: '/api/users/request',
        summary: 'Request user role',
        tags: ['Users'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(property: "role", type: "string")
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Request registered successfully',
                content: new OA\JsonContent(type: 'object')
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthorized'
            ),
            new OA\Response(
                response: 400,
                description: 'Invalid input'
            )
        ]
    )]
    #[Route('/users/request', name: 'send_user_request', methods: ['POST'])]
    public function sendUserRequest(Request $request, Keycloak $auth, SerializerInterface $serializer): JsonResponse
    {
        if ($auth->isGuest()) {
            return new JsonResponse(['message' => 'Unauthorized'], status: 401);
        }
        $params = $request->getContent();
        $params = json_decode($params, true);
        require __DIR__."/../Entity/Users.php";
        $output = sendUserRequest($auth, $params);
        $content = $serializer->serialize($output, 'json');
        return new JsonResponse($content, json: true);
    }

    #[Route('/users/{user_sub}/public-key', name: 'register_user_key', methods: ['POST'])]
    public function registerUserKey(Request $request, Keycloak $auth, SerializerInterface $serializer, string $user_sub): JsonResponse
    {
        if ($auth->isGuest()) {
            return new JsonResponse(['message' => 'Unauthorized'], status: 401);
        }
        $user = $auth->getDetails();
        if ($user['sub'] !== $user_sub) {
            return new JsonResponse(['message' => 'Unauthorized'], status: 401);
        }
        $params = json_decode($request->getContent(),true);
        $publicKey = $params['params']['userKey'];
        $keyType = $params['params']['type'];
        
        // check public key //
        if ($keyType === 'ssh'){
            $tmpfname = tempnam("/tmp", "checkKey");
            $tmpfname .= ".pub";
            $handle = fopen($tmpfname, "w");
            fwrite($handle,$publicKey);
            fclose($handle);
            $cmd = 'which ssh-keygen && echo "OK" || echo "KO" ';
            $check = exec($cmd);
            if ($check == 'OK'){
                $cmd = "ssh-keygen -l -f ".$tmpfname." 2>/dev/null && echo 'OK' || echo 'KO'";
                $check = exec($cmd);
                if ($check != "OK"){
                    error_log($cmd);
                    throw new Exception("Error: the provided key is not valid", 400);
                }            
                else{
                    unlink($tmpfname); 
                }
            }            
        }
        else if ($keyType === 'c4gh'){
            if (strlen($publicKey) < 10){
                throw new Exception("Error: the provided key is too short", 401);                
            }
        }


        
        if (isset($user[$keyType.'-public-key'])){
            foreach($user[$keyType.'-public-key'] as $pc){
                if (strpos($publicKey,$pc) === 0){
                    return new JsonResponse(['message' => 'Public key is already associated to the user'], status: 400);
                }
            }            
            $user[$keyType.'-public-key'][] = $publicKey;
        }
        else {
            $user[$keyType.'-public-key'] = array($publicKey);
        }
        $test = $auth->updateAttribute($keyType."-public-key",$user[$keyType.'-public-key']);
        
        $log = array(
            "user_id" => $user['id'],
            "key_type" => $keyType,
            "key_sha" => hash('sha256',$publicKey),
            "action_type_id" => "CRE"
        );
        \DB::insert("user_key_log",$log);
        if ($user['email']){
            $to      = $user['email'];
            $subject = 'FEGA: new '.$keyType." public key";
            $message = 'A new '.strtoupper($keyType)." public key has been registered in FEGA.\r\n\r\n";
            $message .= 'Its SHA256 hash is: '.hash('sha256',$publicKey)."\r\n\r\n";
            $headers = array(
                'From' => 'no-reply@sib.swiss',
                'Reply-To' => 'helpdesk@sib.swiss',
                'X-Mailer' => 'PHP/' . phpversion()
            );
            mail($to, $subject, $message, $headers);
        }

        $content = $serializer->serialize($test, 'json');
        
        return new JsonResponse($content, json: true);

    }

    #[Route('/users/{user_sub}/public-key/{key_type}/{public_key}', name: 'delete_user_key', methods: ['DELETE'])]
    public function deleteUserKey(Request $request, Keycloak $auth, SerializerInterface $serializer, string $user_sub, string $key_type, string $public_key): JsonResponse
    {
        if ($auth->isGuest()) {
            return new JsonResponse(['message' => 'Unauthorized'], status: 401);
        }
        $user = $auth->getDetails();
        if ($user['sub'] !== $user_sub) {
            return new JsonResponse(['message' => 'Unauthorized'], status: 401);
        }
        $publicKey = '';
        if (!isset($user[$key_type.'-public-key'])){
            $user[$key_type.'-public-key'] = array();
        }
        foreach($user[$key_type.'-public-key'] as $pc){
            if (strpos($public_key,$pc) !== FALSE || strpos($pc, $public_key) !== FALSE){
                $publicKey = $pc;
            }
        }
        if (!$publicKey){
            return new JsonResponse(['message' => 'Public key is not associated to the user'], status: 400);
        }
        $validKeys = array();
        foreach($user[$key_type.'-public-key'] as $k){
            if ($k !== $publicKey){
                $validKeys[] = $k;
            }
        }
        $test = $auth->updateAttribute($key_type."-public-key",$validKeys);

        $log = array(
            "user_id" => $user['id'],
            "key_type" => $keyType,
            "key_sha" => hash('sha256',$publicKey),
            "action_type_id" => "DEL"
        );
        \DB::insert("user_key_log",$log);
        if ($user['email']){
            $to      = $user['email'];
            $subject = 'FEGA:'.$keyType." public key deleted";
            $message = 'A '.strtoupper($keyType)." public key has been deleted.\r\n\r\n";
            $message .= 'Its SHA256 hash is: '.hash('sha256',$publicKey)."\r\n\r\n";
            $headers = array(
                'From' => 'no-reply@sib.swiss',
                'Reply-To' => 'helpdesk@sib.swiss',
                'X-Mailer' => 'PHP/' . phpversion()
            );
            mail($to, $subject, $message, $headers);
        }

        $content = $serializer->serialize($test, 'json');
        
        return new JsonResponse($content, json: true);

    }


    #[OA\Get(
        path: '/api/roles',
        summary: 'Get all user roles',
        tags: ['Users'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful response',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(type: 'object'))
            )
        ]
    )]
    #[Route('/roles', name: 'get_roles', methods: ['GET'])]
    public function getRoles(Request $request, Keycloak $auth, SerializerInterface $serializer): JsonResponse
    {
        if ($auth->isGuest()) {
            return new JsonResponse(['message' => 'Unauthorized'], status: 401);
        }
        require __DIR__."/../Entity/Users.php";
        $roles = getRoles();
        $content = $serializer->serialize($roles, 'json');
        return new JsonResponse($content, json: true);
    }
}
