<?php

namespace App\Http\Controllers\Admin;

use App\ActorDirector;
use App\Episodes;
use Illuminate\Http\Request;
use Session;
use App\Genres;
use App\Language;
use App\Movies;
use App\Season;
use App\Series;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CrawlNguonController extends MainAdminController
{
    public function __construct()
    {
        $this->middleware('auth');
        parent::__construct();
    }
    public function crawlnguon()
    {
        $page_title = "Crawl NguonTV";
        // call api
        return view('admin.pages.crawl_nguon', [
            'page_title' => $page_title,
        ]);
    }

    /**
     * Check API to show info films list from nguonTV
     *
     * @param  mixed $request
     * @return void
     */
    public function check_nguon(Request $request)
    {
        try {
            $url = $request->api;
            $url = strpos($url, '?') === false ? $url .= '?' : $url .= '&';
            $full_url = $url . http_build_query(['ac' => 'list', 'limit' => 30, 'pg' => 1]);
            $latest_url = $url . http_build_query(['ac' => 'list', 'limit' => 30, 'pg' => 1, 'h' => 24]);

            $full_response = Http::get($full_url);
            $latest_response = Http::get($latest_url);
            $data = json_decode($full_response->body());
            $latest_data = json_decode($latest_response->body());

            if (!$data) {
                return response()->json(['code' => 999, 'message' => 'Mẫu JSON không đúng, không hỗ trợ thu thập']);
            }
            $page_array = array(
                'code'              => 1,
                'last_page'         => $data->pagecount,
                'update_today'      => $latest_data->total,
                'total'             => $data->total,
                'full_list_page'    => range(1, $data->pagecount),
                'latest_list_page'  => range(1, $latest_data->pagecount),
            );
            return response()->json($page_array);
        } catch (\Throwable $th) {
            return response()->json(['code' => 999, 'message' => 'Có lỗi! Vui lòng kiểm tra lại']);
        }
    }

    /**
     * Crawl film by id from NguonTv
     *
     * @param  mixed $request API url from nguonTV
     * @return void json status and message
     */
    public function crawl_by_id(Request $request)
    {
        try {
            # code...
            //Call api to get films info by id
            $url = $request->api;
            $params = $request->param;
            $url = strpos($url, '?') === false ? $url .= '?' : $url .= '&';

            $response = Http::get($url . $params);
            $response = $this->filter_tags($response->body());
            $data = json_decode($response, true);
            if (!$data) {
                return request()->json(['code' => 999, 'message' => 'Mẫu JSON không đúng, không hỗ trợ thu thập']);
            }

            $movie_data = $this->refined_data($data['list']);
            // return $movie_data;
            if ($movie_data['type'] === "single_movies") { //single_movie
                //Check duplicated from database
                $check_movie_duplicate = Movies::where('imdb_votes', '=', $movie_data['movie_id'])->first();
                if ($check_movie_duplicate) {
                    $this->update_movie($movie_data);
                    return response()->json(array(
                        'code' => 1,
                        'message' => $movie_data['title'] . ' (' . $movie_data['movie_id'] . ')' . ' : Cập nhật thành công.',
                    ));
                }
                //Insert Movie
                $this->insert_movie($movie_data);
                return response()->json(array(
                    'code' => 1,
                    'message' => $movie_data['title'] . ' (' . $movie_data['movie_id'] . ')' .  ' : Thu thập thành công.',
                ));
            } elseif ($movie_data['type'] === "tv_series") { // series_movies
                //Check duplicated from database
                $check_series = Series::where('imdb_votes', '=', $movie_data['movie_id'])->first();
                if ($check_series) { // duplicated
                    // Update movies
                    $check_season = Season::where('series_id', '=', $check_series['id'])->first();
                    $this->update_episodes($movie_data, $check_series['id'], $check_season['id']);
                    return response()->json(array(
                        'code' => 1,
                        'message' => $movie_data['title'] . ' (' . $movie_data['movie_id'] .')'. ' : Cập nhật thành công.',
                    ));
                }

                //Insert movies
                $new_series = $this->insert_series($movie_data);
                $new_season = $this->insert_season($movie_data['pic_url'], $new_series['id']);
                $this->insert_episodes($movie_data, $new_series['id'], $new_season['id']);
                return response()->json(array(
                    'code' => 1,
                    'message' => $movie_data['title'] . ' (' . $movie_data['movie_id'] . ')' .  ' : Thu thập thành công.',
                ));
            }
        } catch (\Throwable $e) {
            return response()->json(['code' => 999, 'message' => 'Có lỗi! Vui lòng kiểm tra lại']);
        }
    }

    /**
     * Insert a single movie to movie_videos table
     *
     * @param  mixed $data
     * @return void
     */
    private function insert_movie($data)
    {
        $genres = $this->insert_movies_genres($data['categories']);
        $actor = $this->insert_movies_actor_director($data['actor']);
        $director = $this->insert_movies_actor_director($data['director'], 'director');
        $movie_arr = '';
        foreach ($data['episodes']['server_data'] as $movie) {
            $movie_obj = new Movies;
            $movie_obj->video_access = 'Free';
            $movie_obj->movie_lang_id = $this->insert_movies_language($data['country']);
            $movie_obj->movie_genre_id = $genres;
            $movie_obj->upcoming = 0;
            $movie_obj->video_title = $data['title'] ?? $data['org_title'];
            $movie_obj->release_date = strtotime($data['release_date']);
            $movie_obj->video_description = $data['content'];
            $movie_obj->actor_id = $actor;
            $movie_obj->director_id = $director;
            $movie_obj->video_slug = $data['title-slug'];
            $movie_obj->video_image_thumb = ($data['pic_url']);
            $movie_obj->video_image = ($data['pic_url']);
            $movie_obj->video_type = 'HLS';
            $movie_obj->video_quality = 0;
            $movie_obj->video_url = $movie['ep_link'];
            $movie_obj->imdb_votes = $data['movie_id']; //Save NguonTv movie_id to imdb_votes column
            $movie_obj->save();
            $movie_arr = $movie_obj;
        }
        return $movie_arr;
    }

    /**
     * Update a single movie to movie_videos table
     *
     * @param  mixed $data
     * @return void
     */
    private function update_movie($data)
    {
        $movie_arr = '';
        foreach ($data['episodes']['server_data'] as $movie) {
            $movie_obj = Movies::updateOrCreate(
                ['imdb_votes' => $data['movie_id']],
                ['video_url' => $movie['ep_link']]
            );
            $movie_arr = $movie_obj;
        }
        return $movie_arr;
    }

    /**
     * Insert a series movies in series table
     *
     * @param  mixed $data
     * @return void
     */
    private function insert_series($data)
    {
        $genres = $this->insert_movies_genres($data['categories']);
        $actor = $this->insert_movies_actor_director($data['actor']);
        $director = $this->insert_movies_actor_director($data['director'], 'director');

        $movie_exists = Series::where('imdb_votes', '=', $data['movie_id'])->first();
        if ($movie_exists) {
            return  $movie_exists;
        }
        $series_obj = new Series;
        $series_obj->series_lang_id = $this->insert_movies_language($data['country']);
        $series_obj->series_genres = $genres;
        $series_obj->upcoming = 0;
        $series_obj->series_access = 'Free';
        $series_obj->series_name = $data['title'];
        $series_obj->series_slug = $data['title-slug'];
        $series_obj->series_info = $data['content'];
        $series_obj->actor_id = $actor;
        $series_obj->director_id = $director;
        $series_obj->series_poster = ($data['pic_url']);
        $series_obj->imdb_votes = $data['movie_id']; //Save NguonTV movie_id to imdb_votes column
        $series_obj->save();
        return $series_obj;
    }

    /**
     * insert a season in season table
     *
     * @param  mixed $image_url
     * @param  mixed $series_id
     * @return void
     */
    private function insert_season($image_url, $series_id)
    {
        $season = Season::firstOrCreate(
            ['series_id' => $series_id],
            ['season_name' => 'Phần 1', 'season_slug' => 'phan-1', 'season_poster' => $image_url, 'status' => 1]
        );
        return $season;
    }

    /**
     * insert episodes to episodes table
     *
     * @param  mixed $data
     * @param  mixed $series_id
     * @param  mixed $season_id
     * @return void
     */
    private function insert_episodes($data, $series_id, $season_id)
    {
        $episodes_arr = array();
        foreach ($data['episodes']['server_data'] as $episode) {
            $episodes_obj = new Episodes;
            $episodes_obj->video_slug = $data['title-slug'] . "-" . $episode['ep_slug'];
            $episodes_obj->episode_series_id = $series_id;
            $episodes_obj->episode_season_id = $season_id;
            $episodes_obj->video_access = 'Free';
            $episodes_obj->video_title = "Tập " . $episode['ep_name'] . " - " . "Episode " . $episode['ep_name'];
            $episodes_obj->release_date = strtotime($data['release_date']);
            $episodes_obj->video_description = $data['content'];
            $episodes_obj->video_image = ($data['pic_url']);
            $episodes_obj->video_type = 'HLS';
            $episodes_obj->video_quality = 0;
            $episodes_obj->video_url = $episode['ep_link'];
            $episodes_obj->imdb_votes = $data['movie_id'];
            $episodes_obj->save();
            $episodes_arr[] = $episodes_obj;
        }
        return $episodes_arr;
    }

    /**
     * update episodes to episodes table
     *
     * @param  mixed $data
     * @param  mixed $series_id
     * @param  mixed $season_id
     * @return void
     */
    private function update_episodes($data, $series_id, $season_id)
    {
        $episodes_arr = array();
        foreach ($data['episodes']['server_data'] as $episode) {
            $episodes_obj = Episodes::updateOrCreate(
                ['video_slug' => $data['title-slug'] . "-" . $episode['ep_slug'], 'imdb_votes' =>  $data['movie_id']],
                [
                    'episode_series_id' => $series_id,
                    'episode_season_id' => $season_id,
                    'video_access' => 'Free',
                    'video_title' => "Tập " . $episode['ep_name'] . " - " . "Episode " . $episode['ep_name'],
                    'release_date' => strtotime($data['release_date']),
                    'video_description' => $data['content'],
                    'video_image' => ($data['pic_url']),
                    'video_type' => 'HLS',
                    'video_quality' => 0,
                    'video_url' => $episode['ep_link'],
                ]
            );
            $episodes_arr[] = $episodes_obj;
        }
        return $episodes_arr;
    }

    /**
     * insert_movies_genres
     *
     * @param  mixed $categories
     * @return void
     */
    private function insert_movies_genres($categories = [])
    {
        $array_id_result = [];
        if (!empty($categories)) {
            foreach ($categories as $category) {
                $genre = Genres::firstOrCreate(
                    ['genre_name' => addslashes($category)],
                    ['genre_slug' => Str::slug($category), 'status' => 1]
                );
                $array_id_result[] = $genre->id;
            }
        }
        return implode(",", $array_id_result);
    }

    /**
     * insert_movies_actor_director
     *
     * @param  mixed $lists list of name
     * @param  mixed $role actor or director
     * @return void
     */
    private function insert_movies_actor_director($lists = [], $role = 'actor')
    {
        $array_id_result = [];

        if (!empty($lists)) {
            foreach ($lists as $list) {
                $result = ActorDirector::firstOrCreate(
                    ['ad_slug' => Str::slug($list)],
                    ['ad_type' => $role, 'ad_name' => $list]
                );
                $array_id_result[] = $result->id;
            }
        }
        return implode(",", $array_id_result);
    }

    /**
     * insert_movies_language
     *
     * @param  mixed $language
     * @return void
     */
    private function insert_movies_language($language)
    {
        $result = Language::firstOrCreate(
            ['language_name' => addslashes($language)],
            ['language_slug' => Str::slug($language), 'status' => 1]
        );
        return $result->id;
    }


    /**
     * get_movies_page action Callback function
     *
     * @param  string $api        url
     * @param  string $param      query params
     * @return json   $page_array List movies in page
     */
    public function get_movies_page(Request $request)
    {
        try {
            $url = $request->api;
            $params = $request->param;
            $type_id = $request->type_id;

            $url = strpos($url, '?') === false ? $url .= '?' : $url .= '&';
            if ($type_id !== null || $type_id !== '0') {
                $params .= '&t=' . $type_id;
            }
            $response = Http::get($url . $params);

            $data = json_decode($response->body());
            if (!$data) {
                return response()->json(['code' => 999, 'message' => 'Mẫu JSON không đúng, không hỗ trợ thu thập']);
            }
            $page_array = array(
                'code'          => 1,
                'movies'        => $data->list,
            );
            return response()->json($page_array);
        } catch (\Throwable $th) {
            return response()->json(['code' => 999, 'message' => $th]);
        }
    }

    /**
     * Filter html tags in api response
     *
     * @param  string   $rs     response
     * @param  array    $rs     response
     */
    private function filter_tags($rs)
    {
        $rex = array('{:', '<script', '<iframe', '<frameset', '<object', 'onerror');
        if (is_array($rs)) {
            foreach ($rs as $k2 => $v2) {
                if (!is_numeric($v2)) {
                    $rs[$k2] = str_ireplace($rex, '*', $rs[$k2]);
                }
            }
        } else {
            if (!is_numeric($rs)) {
                $rs = str_ireplace($rex, '*', $rs);
            }
        }
        return $rs;
    }
    /**
     * Refine movie data from api response
     *
     * @param  array  $array_data   raw movie data
     * @param  array  $movie_data   movie data
     */
    private function refined_data($array_data)
    {
        foreach ($array_data as $data) {
            if ($data['type_id'] == 1) {
                $type = "single_movies";
                $duration = $data['vod_weekday'];
                $status = 'completed';
            } else {
                $type = "tv_series";
                if (strpos($data['vod_remarks'], 'tập đang chiếu') !== false || strpos($data['vod_remarks'], 'cập nhật đến') !== false) {
                    $status = 'ongoing';
                } else {
                    $status = 'completed';
                }
            }
            $categories = array_merge($this->format_text($data['type_name']), $this->format_text($data['vod_class']));
            $tags = [];
            array_push($tags, addslashes($data['vod_name']));
            $tags = array_merge($tags, $this->format_text($data['vod_class']));

            $movie_data = [
                'title' => addslashes($data['vod_name']),
                'title-slug' => Str::slug($data['vod_name']),
                'org_title' => addslashes($data['vod_en']) ? $data['vod_en'] : $data['vod_name'],
                'pic_url' => $this->save_image_from_url($data['vod_pic']),
                'actor' => $this->format_text($data['vod_actor']) ?? "",
                'director' => $this->format_text($data['vod_director']) ?? "",
                'episode' => $type == 'single_movies' ? 'Full' : $data['vod_remarks'],
                'episodes' => $this->get_play_url($data['vod_play_note'], $data['vod_play_url']),
                'country' => $data['vod_area'],
                'language' => 'Vietsub',
                'year' => $data['vod_year'],
                'release_date' => $data['vod_time'],
                'content' => preg_replace('/\\r?\\n/s', '', $data['vod_content']),
                'tags' => $tags,
                'quality' => ['HD', '1080P', '720P'][random_int(0, 2)],
                'type' => $type,
                'categories' => $categories,
                'duration' => $duration ?? "",
                'status' => $status,
                'movie_id' => $data['vod_id'],
            ];
        }
        return $movie_data;
    }
    /**
     * Uppercase the first character of each word in a string
     *
     * @param  string   $string     format string
     * @param  array    $arr        string array
     */
    private function format_text($string)
    {
        $string = str_replace(array('/', '，', '|', '、', ',,,'), ',', $string);
        $arr = explode(',', addslashes($string));
        foreach ($arr as &$item) {
            $item = ucwords(trim($item));
            $item = mb_strtoupper(mb_substr($item, 0, 1)) . mb_substr($item, 1, mb_strlen($item));
        }
        return $arr;
    }

    /**
     * Split play URL from string to array
     *
     * @param  string    $note    string use for explode hsl or embed
     * @param  string    $urls_str string contain all url
     * @return  array     array movie URLs
     */
    private function get_play_url($note, $urls_str)
    {
        list($embed_links, $hsl_links) = explode($note, $urls_str); // [embed, hsl]
        $server_info["server_data"] = array();
        $episodes = explode('#', $hsl_links);
        $episodes_sub = explode('#', $embed_links);

        foreach ($episodes as $key => $value) {
            if ($value != '') {
                $extract_url_pattern = "/https?:\\/\\/(?:www\\.)?[-a-zA-Z0-9@:%._\\+~#=]{1,256}\\.[a-zA-Z0-9()]{1,6}\\b(?:[-a-zA-Z0-9()@:%_\\+.~#?&\\/=]*)/";
                preg_match_all($extract_url_pattern, $value, $matches);
                if ($matches) {
                    $ep_link = str_replace('http://', 'https://', $matches[0][0]);
                    $ep_name = count($episodes) > 1 ? $key + 1 : 'Full';
                    $_slug = addslashes($ep_name);
                    $server_info['server_data'][$_slug]['ep_name'] = $ep_name;
                    $server_info['server_data'][$_slug]['ep_slug'] = addslashes($ep_name);
                    $server_info['server_data'][$_slug]['ep_type'] = 'hls';
                    $server_info['server_data'][$_slug]['ep_link'] = $ep_link;
                    $server_info['server_data'][$_slug]['ep_subs'] = null;
                    $server_info['server_data'][$_slug]['ep_listsv'] = array();
                    preg_match_all($extract_url_pattern, $episodes_sub[$key], $sub_matches);
                    $ep_sub_link = str_replace('http://', 'https://', $sub_matches[0][0]);
                    $varSub['ep_listsv_link'] = $ep_sub_link;
                    $varSub['ep_listsv_name'] = 'Dự phòng';
                    $varSub['ep_listsv_type'] = 'embed';
                    array_push($server_info['server_data'][$_slug]['ep_listsv'], $varSub);
                }
            }
        }
        return $server_info;
    }
    private function save_image_from_url($url)
    {
        $url = str_replace('https://', 'http://', $url);
        $img_name = pathinfo($url)['basename'];
        if ($this->check_duplicate_img($img_name, 'images')) {
            return 'upload/images/' . $img_name;
        }
        $content = file_get_contents($url);
        $save_img = Storage::disk('images')->put($img_name, $content);
        if ($save_img) {
            return 'upload/images/' . $img_name;
        } else {
            return '';
        }
    }
    /**
     * check images exists in local
     *
     * @param  mixed $img_name
     * @param  mixed $path
     * @return void
     */
    private function check_duplicate_img($img_name, $path)
    {
        return Storage::disk($path)->exists($img_name);
    }
}
