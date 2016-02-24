<?php
/**
 * Pico Youtube List
 * Youtubeの動画再生リストを取得し、ページとして追加する自動更新モジュール
 *
 * @author TakamiChie
 * @link http://onpu-tamago.net/
 * @license http://opensource.org/licenses/MIT
 * @version 1.0
 */
class Pico_YoutubeList {
  
  private $settings;
  
  function __construct(){
    define("API_GETPLAYLISTITEM", "https://www.googleapis.com/youtube/v3/playlistItems");
    define("API_GETPLAYLISTS", "https://www.googleapis.com/youtube/v3/playlists");
    define("URL_PLAYLIST", "https://www.youtube.com/playlist?list=%s");
    define("URL_MOVIE", "https://www.youtube.com/watch?v=%s&index=%d&list=%s");
    define("URL_EMBED_PLAYLIST", "https://www.youtube.com/embed/videoseries?list=%s");
    define("URL_EMBED_MOVIE", "https://www.youtube.com/embed/%s?list=%s");
    define("STATE_GETPLAYLIST", "playlists");
    define("STATE_GETPLAYLISTITEM", "playlistitems");
    define("EMBEDCODE", "<iframe width='560' height='315' src='%s' frameborder='0' allowfullscreen></iframe>");
  }
  
  public function run($settings) {
    if(empty($settings["youtube"]) || 
      empty($settings["youtube"]["apikey"])) {
      return;
    }
    $this->settings = $settings;
    
    $apikey = $settings["youtube"]["apikey"];
    $channels = !empty($settings["youtube"]["channels"]) ? $settings["youtube"]["channels"] : NULL;
    $playlists = !empty($settings["youtube"]["playlists"]) ? $settings["youtube"]["playlists"] : NULL;

    // 動画フォルダのクリーン処理
    $l = array();
    $l = !empty($channels) ? array_merge($l, $channels) : $l;
    $l = !empty($playlists) ? array_merge($l, $playlists) : $l;
    foreach($l as $li){
      $this->removeBeforeScanned($this->settings["content_dir"] . $li["directory"]);
    }

    // 動画の読み込み処理
    if(!empty($channels)){
      foreach($channels as $channel){
        $this->loadchannel($apikey, $channel);
      }
    }
    
    if(!empty($playlists)){
      foreach($playlists as $playlist){
        $this->loadplaylist($apikey, $playlist);
      }
    }
  }
  
  private function loadplaylist($apikey, $playlist) {
    if(empty($playlist) ||
      empty($playlist["playlist"]) ||
      empty($playlist["directory"])){
      return;
    }
    echo ">> playlist loading\n";
    $this->loadresource($apikey, $playlist["playlist"], $playlist["directory"],
      !empty($playlist["exclude"]) ? $playlist["exclude"] : "", STATE_GETPLAYLISTITEM);
  }
  
  private function loadchannel($apikey, $channel) {
    if(empty($channel) ||
      empty($channel["channel"]) ||
      empty($channel["directory"])){
      return;
    }
    echo ">> channel loading\n";
    $this->loadresource($apikey, $channel["channel"], $channel["directory"],
      !empty($channel["exclude"]) ? $channel["exclude"] : "", STATE_GETPLAYLIST);
  }

  private function loadresource($apikey, $id, $directory, $exclude, $state) {
    echo "> ${id} to ${directory}\n";
    // 初期処理
    $cdir = $this->settings["content_dir"] . $directory;
    $cachedir = LOG_DIR . "youtube/";
    $cachefile = $cachedir . $id . ".json";
    $excludes = explode(",", $exclude);
    if(!file_exists($cachedir)){
      mkdir($cachedir, "0500", true);
    }
    $query = array(
      "part" => "snippet,status",
      "maxResults" => 50,
      "key" => $apikey,
    );
    if($state == STATE_GETPLAYLISTITEM) {
      // プレイリストアイテム取得時の独自処理
      $query["playlistId"] = $id;
      $apiurl = API_GETPLAYLISTITEM;
    }else if($state == STATE_GETPLAYLIST) {
      // チャンネルプレイリスト取得時の独自処理
      $query["channelId"] = $id;
      $apiurl = API_GETPLAYLISTS;
    }

    /* テキストファイル作成処理 */
    try{
      $responce;
      // まずはJSON読み込み
      $content = $this->curl_getcontents($apiurl . "?" . http_build_query($query), $responce);
      file_put_contents($cachefile, $content);
      $json = json_decode($content, true);
      if($responce['http_code'] >= 300){
        throw new Exception($json['error']["message"]);
      }
      foreach($json["items"] as $j){
        if(in_array($j["id"], $excludes)){
          continue;
        }
        $s = $j["snippet"];
        if($state == STATE_GETPLAYLISTITEM) {
          $url = sprintf(URL_MOVIE, $s["resourceId"]["videoId"], $s["position"], $s["playlistId"]);
          $iframeurl =  sprintf(URL_EMBED_MOVIE, $s["resourceId"]["videoId"], $s["playlistId"]);
          $title = $s["title"];
          $description = $s["description"];
        }else if($state == STATE_GETPLAYLIST) {
          $url = sprintf(URL_PLAYLIST, $j["id"]);
          $iframeurl =  sprintf(URL_EMBED_PLAYLIST, $j["id"]);
          $title = $s["localized"]["title"];
          $description = $s["localized"]["description"];
        }else{
          throw new Exception("Unsupported State");
        }
        
        echo "${title}:${description}\n";
        $iframecode = sprintf(EMBEDCODE, $iframeurl);
        // 非Publicなもの、説明文が空なものは公開しない
        if($j["status"]["privacyStatus"] != "public" || empty($description)) continue;
        // mdファイル作成
        $page = "/*\n";
        $page .= sprintf("  Title: %s\n", $title);
        $page .= sprintf("  Author: %s\n", $s["channelTitle"]);
        $page .= sprintf("  Date: %s\n", $s["publishedAt"]);
        $page .= sprintf("  Description: %s\n", str_replace(array("\n", "\r"), " ", $description));
        $page .= sprintf("  URL: %s\n", $url);
        $page .= sprintf("  Tag: %s\n", "embed");
        $page .= sprintf("  Image: %s\n", $s["thumbnails"]["medium"]["url"]);
        $page .= "*/\n";
        $page .= $iframecode;

        file_put_contents($cdir . $j["id"] . ".md", $page);
      }
      echo "\n\n";
    }catch(Exception $e){
      echo "Youtube Access Error\n";
      echo $e->getMessage() . "\n";
    }
	}

  /**
   *
   * ファイルをダウンロードする
   *
   * @param string $url URL
   * @param array $responce レスポンスヘッダが格納される配列(参照渡し)。省略可能
   *
   */
  private function curl_getcontents($url, &$responce = array())
  {
    $ch = curl_init();
    curl_setopt_array($ch, array(
      CURLOPT_URL => $url,
      CURLOPT_TIMEOUT => 3,
      CURLOPT_CUSTOMREQUEST => 'GET',
      CURLOPT_SSL_VERIFYPEER => FALSE,
      CURLOPT_RETURNTRANSFER => TRUE,
      CURLOPT_USERAGENT => "Pico"));

    $content = curl_exec($ch);
    if(!curl_errno($ch)) {
      $responce = curl_getinfo($ch);
    } 
    if(!$content){
      throw new Exception(curl_error($ch));
    }
    curl_close($ch);
    return $content;
  }

  /**
   *
   * 以前自動生成した原稿ファイルを全削除する
   *
   * @param string $cdir 対象のファイルが格納されているディレクトリパス
   *
   */
  private function removeBeforeScanned($cdir){
    if(!file_exists($cdir)){
      mkdir($cdir, "0500", true);
    }
    if($handle = opendir($cdir)){
      while(false !== ($file = readdir($handle))){
        if(!is_dir($file) && $file != "index.md"){
          unlink($cdir. "/" . $file);
        }
      }
      closedir($handle);
    }
  }
}

?>
