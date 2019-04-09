<?php
//ホットペッパーグルメから、都道府県別の店舗一覧を取得するスクリプト

class HpbShopListTool {
  var $hpInfo = array(
    "" => array(
          "top" => 'https://www.hotpepper.jp/yoyaku/',
          "type" => "グルメ",
    )
  );

  function __construct() {
    $this->output_dir = './output/';
  }

  function create($type, $pref) {
    Util::log("HPB LIST CREATE START");
    
    require_once 'HTTP/Request2.php';
           
    $this->type = $type;
    $typeinfo = $this->type_list[$type];

    $filename = $typeinfo['fileident']."_".sprintf("%02d.tsv", $pref);
    if (!file_exists($this->output_dir)) {
      mkdir($this->output_dir);
    }
    $output = "{$this->output_dir}/".date('Ymd_His')."_{$filename}";
    Util::log($output);

    $conf = new Config();

    $prefName = $conf->prefectures[(int)$pref];
    
    $header = "種別\t店舗id\t店舗名\t都道府県\t住所\t電話番号\t店舗URL\t営業時間\t定休日\t備考\n";
    file_put_contents($output, $header);
    set_time_limit(0);
    
    $url = sprintf($typeinfo['listtop'], $pref);
    $shops = array();
    $nexturl_list = array();
    for ($i = 0; $i < 500; $i++) {
      Util::log("HPB LIST {$url}");
      $ret = $this->getPageShops($url);
      foreach ($ret['shops'] as $s) {
        $line = array();
        $line[] = "{$typeinfo['name']}";
        foreach (array('hpbid', 'name', 'addr', 'tel', 'url', 'range', 'holiday','comment') as $key) {
          if ($key == 'addr') {
            // 県で切りたい
            $line[] = $prefName;
            $line[] = mb_substr($s[$key], mb_strlen($prefName));
          } else {
            $line[] = str_replace("\t", " ", $s[$key]);
          }
        }
        file_put_contents($output, implode("\t", $line)."\n", FILE_APPEND);
      }
      $shops = array_merge($shops, $ret['shops']);
      
      //
      $nexturl = $ret['nextpage'];
      if (empty($nexturl) || isset($nexturl_list[$nexturl])) { // 既に処理済みのページの場合も終了する
        break;
      }
      $nexturl_list[$nexturl] = $nexturl;
      
      $nextpage = "{$nexturl}";
      $url = $nextpage;
    }
    file_put_contents($output, "END", FILE_APPEND);
    Util::log("HPB LIST CREATE END");
  }

  function getPageShops($url) {
    Util::log("pageshops;{$url}");
  
    $xml = $this->request($url, $type);

    list($title) = $xml->xpath("//title");

    // ページ数
    list($maxpagestr) = $xml->xpath("//p[@class='pa bottom0 right0']");
    preg_match("/\/([0-9]+)ページ/", "$maxpagestr", $matches);
    $maxpage = $matches[1];

    list($next) = $xml->xpath("//a[.='次へ']");
    $nextpage = "{$next['href']}";
    $shops = array();

    if (strpos($nextpage, "http") === false) {
      $nextpage = "https://beauty.hotpepper.jp".$nextpage;
    }
    
    // 店舗リストshutoku 
    foreach ($xml->xpath("//li[@class='searchListCassette']") as $sxml) {
      $shop = array();
      list($name) = $sxml->xpath(".//h3/a");
      $shop['name'] = "{$name}";
      $shop['hpburl'] = "{$name['href']}";

      $shop = $this->getShopDetail("{$shop['hpburl']}");

      $shops[] = $shop;
    }
    return array("shops" => $shops,
           "nextpage" => $nextpage);
  }

  function request($url, $ident) {

    if (isset($this->requetedList[$url])) {
      // 同じURLにはアクセスしないで終了してしまう
      exit;
    }
    $this->urlcache[$url] = 1;

    $ident .= "y";
    // テスト用
    if (false && file_exists("/tmp/hpblist_{$ident}.html")) {
      $doc = new DOMDocument();
      $doc->loadHTMLFile("/tmp/hpblist_{$ident}.html");
    } else {
      $req = new HTTP_Request2();
      $req->setAdapter('curl');
      $req->setHeader("user-agent", "Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/52.0.2743.116 Safari/537.36");
      $req->setUrl($url);
      $req->setMethod(HTTP_Request2::METHOD_GET);
      $res = $req->send();

      $doc = new DOMDocument();
      $body = $res->getBody();
      $body = str_replace('&nbsp;'," ", $body);
      $body = str_replace("\r\n", "\n", $body);
      $doc->loadHTML($body);
      Util::log($url);

      file_put_contents("/tmp/hpblist_{$ident}.html", $res->getBody());
    }
    
    $xml = simplexml_import_dom($doc);
    return $xml;
  }


  function getShopDetail($shopurl) {
    $xml = $this->request($shopurl, "{$this->type}_shopx");
    list($name) = $xml->xpath(".//p[@class='detailTitle']/a");
    list($addr) = $xml->xpath(".//th[.='住所']/../td");
    list($url) = $xml->xpath(".//th[.='お店のホームページ']/../td/a");
    list($openrange) = $xml->xpath(".//th[.='営業時間']/../td");
    list($holiday) = $xml->xpath(".//th[.='定休日']/../td");
    list($comment) = $xml->xpath(".//th[.='備考']/../td");

    // 電話番号を取得する
    list($telurl) = $xml->xpath(".//th[.='電話番号']/../td/a");
    if ($telurl) {
      $telxml = $this->request("{$telurl['href']}", "{$this->type}_tel");
      list($tel) = $telxml->xpath(".//th[.='電話番号  ']/../td");
      $tel = trim($tel);
    }
    if (!empty($comment)) {
      $comment = trim(htmlspecialchars_decode(strip_tags($comment->asXML())));
    }

    preg_match("/(slnH[0-9]+)/", $shopurl, $matches);
    $ret = array(
      "hpburl" => $shopurl,
      "hpbid" => $matches[1],
      "name" => "{$name}",
      "addr" => "{$addr}",
      "url" => "{$url}",
      "range" => "{$openrange}",
      "holiday" => "{$holiday}",
      "tel" => "{$tel}",
      "comment" => "{$comment}",
    );
    sleep(2);
    return $ret;
  }
}

if (realpath($_SERVER['SCRIPT_FILENAME']) == __FILE__) {
  var_dump(__FILE__.__LINE__);
  if (count($argv) == 3) {
    $c = new HpbShopListTool();
    $c->create($argv[1], $argv[2]);
  } else {
    echo "\n";
  }
}