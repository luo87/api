<?php
require('include/twitteroauth.php');
require('image_proxy.php');
class twip{
    const PARENT_API = 'https://api.twitter.com/';
    const PARENT_SEARCH_API = 'http://search.twitter.com/';
    const ERR_LOGFILE = 'err.txt';
    const LOGFILE = 'log.txt';
    const LOGTIMEZONE = 'Etc/GMT-8';
    const BASE_URL = 'http://yegle.net/twip/';
    const API_VERSION = '1';

    public function twip($options = null){
        $this->parse_variables($options);

        ob_start();
        $compressed = $this->compress && Extension_Loaded('zlib') && ob_start("ob_gzhandler");
        
        if($this->mode=='t'){
            $this->transparent_mode();
        }
        else if($this->mode=='o'){
            $this->override_mode();
        }
        else if($this->mode=='i'){
            $this->override_mode(true);
        }
        else{
            header('HTTP/1.0 400 Bad Request');
        }

        $str = ob_get_contents();
        if ($compressed) ob_end_flush();
        header('Content-Length: '.ob_get_length());
        ob_flush();

        if($this->debug){
            print_r($this);
            print_r($_SERVER);
            file_put_contents('debug',ob_get_contents().$str);
            ob_clean();
        }
        if($this->dolog){
            file_put_contents('log',$this->method.' '.$this->request_uri."\n",FILE_APPEND);
        }
    }

    private function echo_token(){
            $str = 'oauth_token='.$this->access_token['oauth_token']."&oauth_token_secret=".$this->access_token['oauth_token_secret']."&user_id=".$this->access_token['user_id']."&screen_name=".$this->access_token['screen_name'].'&x_auth_expires=0'."\n";
            echo $str;
    }

    private function parse_variables($options){
        //parse options
        $this->parent_api = isset($options['parent_api']) ? $options['parent_api'] : self::PARENT_API;
        $this->parent_search_api = isset($options['parent_search_api']) ? $options['parent_search_api'] : self::PARENT_SEARCH_API;
        $this->api_version = isset($options['api_version']) ? $options['api_version'] : self::API_VERSION;
        $this->debug = isset($options['debug']) ? !!$options['debug'] : FALSE;
        $this->dolog = isset($options['dolog']) ? !!$options['dolog'] : FALSE;
        $this->compress = isset($options['compress']) ? !!$options['compress'] : FALSE;
        $this->oauth_key = $options['oauth_key'];
        $this->oauth_secret = $options['oauth_secret'];

        if(substr($this->parent_api, -1) !== '/') $this->parent_api .= '/';
        if(substr($this->parent_search_api, -1) !== '/') $this->parent_search_api .= '/';

        $this->base_url = isset($options['base_url']) ? trim($options['base_url'],'/').'/' : self::BASE_URL;
        if(preg_match('/^https?:\/\//i',$this->base_url) == 0){
            $this->base_url = 'http://'.$this->base_url;
        }

        //parse $_SERVER
        $this->method = $_SERVER['REQUEST_METHOD'];


        $this->parse_request_uri();
    }

    private function override_mode($imageproxy = FALSE){
        $tokenfile = glob('oauth/'.$this->password.'.*');
        if(!empty($tokenfile)){
            $access_token = @file_get_contents($tokenfile[0]);
        }
        if(empty($access_token)){
            header('HTTP/1.1 401 Unauthorized');
            header('WWW-Authenticate: Basic realm="Twip4 Override Mode"');
            echo 'You are not allowed to use this API proxy';
            exit();
        }
        $access_token = unserialize($access_token);
        $this->access_token = $access_token;

        if($this->request_uri == 'oauth/access_token'){
            $this->echo_token();
            return;
        }

        if($imageproxy){
            if($this->method=='POST'){
                echo imageUpload($this->oauth_key, $this->oauth_secret, $this->access_token);
            }else{
                echo 'The image proxy needs POST method.';
            }
            return;
        }

        if($this->request_uri == null){
            echo 'click <a href="'.$this->base_url.'oauth.php">HERE</a> to get your API url';
            return;
        }
        $this->parameters = $this->get_parameters();
        $this->uri_fixer();
        $this->connection = new TwitterOAuth($this->oauth_key, $this->oauth_secret, $this->access_token['oauth_token'], $this->access_token['oauth_token_secret']);
        switch($this->method){
            case 'POST':
                echo $this->connection->post($this->request_uri,$this->parameters);
                break;
            case 'DELETE':
                echo $this->connection->delete($this->request_uri,$this->parameters);
                break;
            default:
                echo $this->connection->get($this->request_uri);
                break;
        }
    }

    private function transparent_mode(){
        $this->uri_fixer();
        $ch = curl_init($this->request_uri);
        $this->request_headers = OAuthUtil::get_headers();
        if($this->api_type == 'search'){
            $this->request_headers['Host'] = 'search.twitter.com';
        }
        else{
            $this->request_headers['Host'] = 'api.twitter.com';
        }
        if(isset($this->request_headers['Content-Type']) && 
                $this->request_headers['Content-Type'] == 'application/x-www-form-urlencoded' ){
            $this->parameters = $this->get_parameters(false);
        }else{
            $this->parameters = $this->get_parameters(true);
        }
        $forwarded_headers = array(
            'Host',
            'User-Agent',
            'Authorization',
            'Content-Type',
            'X-Forwarded-For',
            'Expect',
            );
        foreach($forwarded_headers as $header){
            if(isset($this->request_headers[$header])){
                $this->forwarded_headers[] = $header.': '.$this->request_headers[$header];
            }
        }
        curl_setopt($ch,CURLOPT_HTTPHEADER,$this->forwarded_headers);
        curl_setopt($ch,CURLOPT_HEADERFUNCTION,array($this,'headerfunction'));
        if($this->method != 'GET'){
            curl_setopt($ch,CURLOPT_CUSTOMREQUEST,$this->method);
            curl_setopt($ch,CURLOPT_POSTFIELDS,$this->parameters);
        }
        curl_setopt($ch,CURLOPT_RETURNTRANSFER,TRUE);
        $ret = curl_exec($ch);
        //fixme:redirect request back to twip,this is nasty and insecure...
        if(strpos($this->request_uri,'oauth/authorize?oauth_token=')!==NULL){
            $ret = str_replace('<form action="https://api.twitter.com/oauth/authorize"','<form action="'.$this->base_url.'t/oauth/authorize"',$ret);
            $ret = str_replace('<div id="signin_form">','<h1><strong style="color:red">Warning!This page is proxied by twip and therefore you may leak your password to API proxy owner!</strong></h1><div id="signin_form">',$ret);
        }
        echo $ret;
    }

    private function uri_fixer(){
        if( isset($_SERVER['HTTP_USER_AGENT']) && substr($_SERVER['HTTP_USER_AGENT'],0,6) == 'twhirl' ){
            $this->request_uri = str_replace('api/','',$this->request_uri);//remove "api/"
        }
        if(isset($this->api_type) && $this->api_type == 'search'){
            $this->request_uri = $this->parent_search_api.$this->request_uri;
        }
        else{
            if(strpos($this->request_uri,'oauth/') === 0 || preg_match('/^[0-9]\/(.*)/',$this->request_uri)){
                $this->request_uri = $this->parent_api.$this->request_uri;
            }else{
                $this->request_uri = $this->parent_api.$this->api_version.'/'.$this->request_uri;
            }
        }
    }

    private function parse_request_uri(){
        $full_request_uri = substr($_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'],strlen(preg_replace('/^https?:\/\//i','',$this->base_url)));
        if(strpos($full_request_uri,'o/')===0){
            list($this->mode,$this->password,$this->request_uri) = explode('/',$full_request_uri,3);
            $this->mode = 'o';
        }
        elseif(strpos($full_request_uri,'t/')===0){
            list($this->mode,$this->request_uri) = explode('/',$full_request_uri,2);
            $this->mode = 't';
        }
        elseif(strpos($full_request_uri,'i/')===0){
            list($this->mode,$this->password,$this->request_uri) = explode('/',$full_request_uri,3);
            $this->mode = 'i';
        }
        $this->request_uri = preg_replace('/\/+/','/',$this->request_uri);
        if((strpos($this->request_uri,'search.') === 0)){
            $this->api_type = 'search';
        }
    }

    private function headerfunction($ch,$str){
        if(strpos($str,'Content-Length:')!==NULL){
            header($str);
        }
        $this->response_headers[] = $str;
        return strlen($str);
    }

    private function get_parameters($returnArray = TRUE){
        $data = file_get_contents('php://input');
        if(!$returnArray) return $data;
        $ret = array();
        parse_str($data,$ret);
        return $ret;
    }
}
?>
