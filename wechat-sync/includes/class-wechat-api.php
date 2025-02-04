<?php
class WeChat_API {
    private $appid;
    private $secret;
    private $token;

    public function __construct($appid, $secret, $token) {
        $this->appid = $appid;
        $this->secret = $secret;
        $this->token = $token;
    }

    /**
     * 获取Access Token
     */
    public function get_access_token() {
        $transient_key = 'wechat_access_token';
        $access_token = get_transient($transient_key);

        if (!$access_token) {
            $response = wp_remote_get(
                "https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential&appid={$this->appid}&secret={$this->secret}"
            );

            if (!is_wp_error($response)) {
                $body = json_decode($response['body'], true);
                $access_token = $body['access_token'];
                set_transient($transient_key, $access_token, expiration: 7000);
            }
        }

        return $access_token;
    }

    /**
     * 上传图文素材到草稿
     * @param mixed $articles
     */
    public function upload_news($articles) {
        $access_token = $this->get_access_token();
        // $url = "https://api.weixin.qq.com/cgi-bin/material/add_news?access_token={$access_token}";   //接口已过期
        $url = "https://api.weixin.qq.com/cgi-bin/draft/add?access_token={$access_token}";
        
        $response = wp_remote_post($url, [
            'body' => json_encode(['articles' => $articles], JSON_UNESCAPED_UNICODE)
        ]);

        return json_decode($response['body'], true);
    }
}