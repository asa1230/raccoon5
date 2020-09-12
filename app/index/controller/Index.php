<?php


namespace app\index\controller;


use app\model\Chapter;
use app\model\FriendshipLink;
use app\common\RedisHelper;
use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\facade\Db;
use think\facade\View;
use app\service\BookService;
use app\model\Banner;
use app\model\Tags;
use app\model\Author;
class Index extends Base
{
    protected $bookService;
    protected function initialize()
    {
        parent::initialize(); // TODO: Change the autogenerated stub
        $this->bookService = app('bookService');
    }

    public function index()
    {
        $pid = input('pid');
        if ($pid) { //如果有推广pid
            cookie('xwx_promotion', $pid); //将pid写入cookie
        }
        $banners = cache('bannersHomepage');
        if (!$banners) {
            $banners = Banner::with('book')->where('banner_order','>', 0)->order('banner_order','desc')->select();
            foreach ($banners as &$banner) {
                if (substr($banner['pic_name'], 0, strlen('http')) != 'http') {
                    $banner['pic_name'] = $this->url . '/static/upload/' . $banner['pic_name'];
                }
            }
            cache('bannersHomepage',$banners, null, 'redis');
        }

        $hot_books = cache('hotBooks');
        if (!$hot_books) {
            $hot_books = $this->bookService->getHotBooks($this->prefix, $this->end_point);
            cache('hotBooks', $hot_books, null, 'redis');
        }
        

        $newest = cache('newestHomepage');
        if (!$newest) {
            $newest = $this->bookService->getBooks( $this->end_point, 'last_time', '1=1', 30);
            cache('newestHomepage', $newest, null, 'redis');
        }
        $ends = cache('endsHomepage');
        if (!$ends) {
            $ends = $this->bookService->getBooks( $this->end_point, 'last_time', [['end', '=', '1']], 30);
            cache('endsHomepage', $ends, null, 'redis');
        }
        $tops = cache('topsHomepage');
        if (!$tops) {
            $tops = $this->bookService->getBooks( $this->end_point, 'last_time', [['is_top', '=', '1']], 30);
            cache('topsHomepage', $tops, null, 'redis');
        }

//        $most_charged = cache('mostCharged');
//        if (!$most_charged) {
//            $arr = $this->bookService->getMostChargedBook($this->end_point);
//            if (count($arr) > 0) {
//                foreach ($arr as $item) {
//                    $most_charged[] = $item['book'];
//                }
//            } else {
//                $arr = [];
//            }
//            cache('mostCharged', $most_charged, null, 'redis');
//        }

        $tags = cache('tags');
        if (!$tags) {
            $tags = Tags::select();
            cache('tags', $tags, null, 'redis');
        }

        $catelist = array(); //分类漫画数组
        $cateItem = array();
        foreach ($tags as $tag) {
            $books = cache('booksFilterByTag:'.$tag);
            if (!$books) {
                $books = $this->bookService->getByTag($tag->tag_name, $this->end_point, 15);
                cache('booksFilterByTag:'.$tag, $books, null, 'redis');
            }
            $cateItem['books'] = $books->toArray();
            $cateItem['tag'] = ['id' => $tag->id, 'tag_name' => $tag->tag_name];
            $catelist[] = $cateItem;
        }
        View::assign([
            'banners' => $banners,
            'banners_count' => count($banners),
            'newest' => $newest,
            'hot' => $hot_books,
            'ends' => $ends,
            'tops' => $tops,
            'tags' => $tags,
            'catelist' => $catelist,
        ]);
        return view($this->tpl);
    }

    public function search()
    {
        $keyword = input('keyword');
        $redis = RedisHelper::GetInstance();
        $redis->zIncrBy($this->redis_prefix . 'hot_search', 1, $keyword); //搜索词写入redis热搜
        $hot_search_json = $redis->zRevRange($this->redis_prefix . 'hot_search', 0, 4, true);
        $hot_search = array();
        foreach ($hot_search_json as $k => $v) {
            $hot_search[] = $k;
        }
        $books = cache('searchresult:' . $keyword);
        if (!$books) {
            $books = $this->bookService->search($keyword, 35);
            foreach ($books as &$book) {
                try {
                    $author = Author::find($book['author_id']);
                    if (is_null($author)) {
                        $author = new Author();
                        $author->author_name = '佚名';
                    }
                    $book['author'] = $author;

                    if ($this->end_point == 'id') {
                        $book['param'] = $book['id'];
                    } else {
                        $book['param'] = $book['unique_id'];
                    }
                } catch (DataNotFoundException $e) {
                    abort(404, $e->getMessage());
                } catch (ModelNotFoundException $e) {
                    abort(404, $e->getMessage());
                }
            }
            cache('searchresult:' . $keyword, $books, null, 'redis');
        }
       
        View::assign([
            'books' => $books,
            'count' => count($books),
            'hot_search' => $hot_search,
            'keyword' => $keyword
        ]);
        return view($this->tpl);
    }
}