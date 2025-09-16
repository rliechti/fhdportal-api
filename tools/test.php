<?php

require __DIR__ . '/include.php';
require __DIR__ . '/keycloak.php';

$json = '{
  "id": "ebf70dbd-5ca0-4874-92e5-cf9f9d3cb480",
  "creationDate": "2025-06-02T14:51:52.053897372Z",
  "members": [
    {
      "userID": "3f9c4f6b-7bf2-404a-87a0-576e74602a9f",
      "status": "approved",
      "role": "admin",
      "email": "martin.fontanet@epfl.ch",
      "firstName": "Martin",
      "lastName": "Fontanet"
    }
  ],
  "status": "pending",
  "name": "Dummy DAC",
  "description": "This is a dummy DAC."
}
';
$dac = json_decode($json,true);
foreach($dac['members'] as $dac_member){
    $users = getKeyCloakUsers(null,"email=".$dac_member['email']);
    foreach($users as $u){
        print_r($u);
    }
}