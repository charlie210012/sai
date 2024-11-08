<?php

namespace Assistent\Sai\src;

include_once __DIR__.'/../../../../app/Principles/Principles.php';

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Principles\Principles;
use Assistent\Directives\ResponseMethods;

class Sai
{
    private $wordsBlocks = [];
    private $apiKey = '';

    private $model;

    private $max_token;
    private $principles = [];

    private $curl;

    public function __construct($config, $apiKey = null,$model,$max_token)
    {

        $this->wordsBlocks = $config['wordsBlocks'];
        $this->principles = new Principles();
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->max_token = $max_token;

        $this->curl = curl_init();
        curl_setopt($this->curl, CURLOPT_URL, 'https://api.openai.com/v1/chat/completions');
        curl_setopt($this->curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($this->curl, CURLOPT_POST, true);
        curl_setopt($this->curl, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey,
        ]);
    }

    public function __destruct()
    {
        curl_close($this->curl);
    }

    public function call(Request $request)
    {
        $inputText = $request->input('message');

        if($this->validationWords($inputText)){
            $generatedText = 'Lo siento como asistente no puedo realizar esa funcion, desea ayuda con algo mas?';
        }else{

            $method = $this->botNatural($request);

            if(!$method){

                $data = [
                    'max_tokens' =>  intval($this->max_token),
                    'model' => $this->model,
                ];

                $data['messages'] = array_merge(
                    array_map(function ($principle) {
                        return [
                            'role' => 'system',
                            'content' => $principle,
                        ];
                    }, $this->principles->__invoke()),
                    [
                        [
                            'role' => 'user',
                            'content' => "Soy el usuario y mi rol es: cliente del chat",
                        ],
                        [
                            'role' => 'user',
                            'content' => $inputText,
                        ]
                    ]
                );

                $generatedText = $this->requestChatGpt($data);

            }else{
                $instacie = '\\App\\Directives\\Methods\\'.$method;
                $generatedText = (new ResponseMethods())->response(new $instacie,$request);

            };

        }

        return  $generatedText;
    }

    public function consult($input){

        $data = [
            'messages'=>[
                [
                    'role' => 'user',
                    'content' => $input,
                ]
            ],
            'max_tokens' => intval($this->max_token),
            'model' => $this->model,
        ];
        return $this->requestChatGpt($data);
    }

    public function requestChatGpt($data)
    {
        curl_setopt($this->curl, CURLOPT_POSTFIELDS, json_encode($data));
        $response = curl_exec($this->curl);
        $responseBody = json_decode($response);
        $generatedText = $responseBody->choices[0]->message->content;
        return $generatedText;
    }

    public function botNatural($request)
    {
        $inputText = $request->input('message');

        $highestPercent = 0;
        $bestMethod = null;
        $sensitivity = config('sai.sensitivity');
        $methods = config('sai.methods');

        foreach($methods as $method){
            if(is_array($method['mensajes'])){
                foreach($method['mensajes'] as $mensaje){
                    $percent = $this->compareText($inputText, $mensaje);
                    if($percent > $highestPercent){
                        $highestPercent = $percent;
                        $bestMethod = $method['method'];
                    }
                }
            } else {
                $percent = $this->compareText($inputText, $method['mensajes']);
                if($percent > $highestPercent){
                    $highestPercent = $percent;
                    $bestMethod = $method['method'];
                }
            }
        }

        return ($highestPercent > $sensitivity) ? $bestMethod : false;
    }

    private function validationWords($input) {
        foreach ($this->wordsBlocks as $word) {
            if (Str::contains($input, $word)) {
                return true;
            }
        }
        return false;
    }

    public function compareText(string $inputText,string $compareText){
        $similarity = similar_text($inputText, $compareText, $percent);
        return $percent;
    }
}
