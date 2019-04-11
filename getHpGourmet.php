<?php
// グルメから店舗の一覧を取得するスクリプト
require_once 'HTTP/Request2.php';

class hpGourmetTool {

    //予約画面のトップ
    var $hpGourmetHp = "https://www.hotpepper.jp/yoyaku/";

    // 取得するお店のタイプごとの情報
    var $type_list = array(
        "居酒屋" => array(
            "genreNo" => "G001",
        ),
        "ダイニングバー・バル" => array(
            "genreNo" => "G002",
        ),
        "創作料理" => array(
            "genreNo" => "G003",
        ),
        "和食" => array(
            "genreNo" => "G004",
        ),
        "洋食" => array(
            "genreNo" => "G005",
        ),
        "イタリアン・フレンチ" => array(
            "genreNo" => "G006",
        ),
    );

    // ホットペッパーグルメの都道府県
    var $hp_pref_list = array("北海道"=>"SA41","青森県"=>"SA51","岩手県"=>"SA52","宮城県"=>"SA53","秋田県"=>"SA54","山形県"=>"SA55","福島県"=>"SA56","茨城県"=>"SA15","栃木県"=>"SA16","群馬県"=>"SA17","埼玉県"=>"SA13","千葉県"=>"SA14","東京都"=>"SA11","神奈川県"=>"SA12","新潟県"=>"SA61","富山県"=>"SA62","石川県"=>"SA63","福井県"=>"SA64","山梨県"=>"SA65","長野県"=>"SA66","岐阜県"=>"SA31","静岡県"=>"SA32","愛知県"=>"SA33","三重県"=>"SA34","滋賀県"=>"SA21","京都府"=>"SA22","大阪府"=>"SA23","兵庫県"=>"SA24","奈良県"=>"SA25","和歌山県"=>"SA26","鳥取県"=>"SA71","島根県"=>"SA72","岡山県"=>"SA73","広島県"=>"SA74","山口県"=>"SA75","徳島県"=>"SA81","香川県"=>"SA82","愛媛県"=>"SA83","高知県"=>"SA84","福岡県"=>"SA91","佐賀県"=>"SA92","長崎県"=>"SA93","熊本県"=>"SA94","大分県"=>"SA95","宮崎県"=>"SA96","鹿児島県"=>"SA97","沖縄県"=>"SA98",);

    public function __construct() {
    }

    // csvファイルとして出力
    public function createCSV($shopType, $pref) {

        $this->shopType = $shopType;

        // OZの店舗URLの一覧を取得(確認完了)
        $shopUrls = $this->getShopUrls($areaId);

        // データを保存するファイルを指定
        $filename = $typeInfo['fileident']."_".sprintf("%02d.tsv", $areaId);
        if (!file_exists($this->output_dir)) {
            mkdir($this->output_dir);
        } 
        $output = "{$this->output_dir}/".date('Ymd_His')."_{$filename}";
        Util::log($output);

        // csvファイルに出力する絡む名を定義
        $header = "種別,店舗名,都道府県,住所,電話番号,営業時間,席数,スタッフ,アクセス,定休日,カード,備考\n";
        $infoTitle = array("name", "address", "tel", "bussineshour", "sheet", "stuff", "access", "holiday", "card", "memo");
        file_put_contents($output, $header);

        // 取得したURLから、各ショップの情報を取得する
        echo "getShopDetail起動";
        foreach($shopUrls as $shop => $shopPagePath){
            $info = $this->getShopInfo($shopPagePath, $shopType);
            // tsvの列に情報を追加する
            echo "getShopInfo完了";
            $tsvRow = array();
            $tsvRow[] = $shopType;
            foreach ($infoTitle as $key) {
                if ($key == 'address') {
                    // 県で切りたい
                    $prefName = $this->oz_pref[$areaId];
                    $tsvRow[] = $prefName;
                    $addinfo = str_replace($prefName, "", $info[$key]);
                    $tsvRow[] = preg_replace("/(\t|\n)/s", "", $addinfo);
                } else {
                    $tsvRow[] = preg_replace("/(\t|\n)/s", "", $info[$key]);
                }
            }
            file_put_contents($output, implode("\t", $tsvRow)."\n", FILE_APPEND);
        }
        file_put_contents($output, "END", FILE_APPEND);
    }

    // urlにリクエストを投げて、bodyをxml形式で取得
    // 引数の$urlは、SimpleXmlの型式だと動作しないので、Stringに変換してから入れること！！！
    function request($url) {
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
        
        $xml = simplexml_import_dom($doc);
        return $xml;
    }

    // 店舗のURL一覧を取得
    function getShopUrls($areaId) {
        $typeInfo = $this->type_list[$this->shopType];

        // areaIdエリアの情報を取得するためのクエリを作成する
        $areas = $this->getAreas($areaId);
        $query = "?AR=".implode(",", $areas);

        // 情報を取得するURL指定OK
        $targetUrl = $typeInfo["listtop"].$query;
        echo "ターゲットURL: ".$targetUrl."\n";

        // xpathの形式でクエリーから条件にあったお店のデータを取得する
        $xml = $this->request($targetUrl);

        // ページネーションの最終ページ番号を取得する
        $targetPath = "///a/@href";
        $nodes = $xml->xpath($targetPath);
        if($nodes[0]){
            preg_match('/pageNo=(?P<pageNo>\d+)/', $nodes[0], $match);
            echo "最終ページ番号：".$match["pageNo"]."\n";
            $lastPageNo = $match["pageNo"];
        } else {
            $lastPageNo = 1;
        }

        // ページごとに表示されている店舗情報のURLを取得する
        echo "ページごとのURLを取得開始";
        $shopUrls = array();
        for ($i = 1; $i <= intval($lastPageNo); $i++) {
            $targetPageUrl = $targetUrl."&pageNo=".$i;
            $xml = $this->request($targetUrl);

            // アクセスしたページから、URLの一覧を取得
            $targetPath = "//h3[class='detailShopNameTitle']/a/@href";
            $nodes = $xml->xpath($targetPath);
            echo "\n".$i."ページ目のShopURLを取得中...";

            // データを出力
            foreach($nodes as $node){
                array_push($shopUrls, $node);
            }
        }
        return $shopUrls;
    }

    // 店舗の情報を取得する
    private function getShopInfo($shopPagePath) {
        echo "getShopDetail\n";

        // 取得するショップのデータを取得
        $typeInfo = $this->type_list[$this->shopType];

        // urlからhtmlのbodyを取得
        $xml = $this->request($shopPagePath);

        //必要な情報を取得する
        list($name) = $xml->xpath("//th[.='施設名']/../td");

        var_dump($xml);
        // 不要なhtmlタグの削除
        $name = strip_tags($name->asXML());

        $info = array(
            "name" => $name
        );
        return $info;
    }
}

// テスト
$cron = new hpGourmetTool();
$cron->createCSV(); //グルメ取得のテスト

/*
if (realpath($_SERVER['SCRIPT_FILENAME']) == __FILE__) {
    var_dump(__FILE__.__LINE__);
    if (count($argv) == 3) {
        $c = new hpGourmetTool();
        $c->create($argv[1], $argv[2]);
    } else {
        echo "\n";
    }
}*/