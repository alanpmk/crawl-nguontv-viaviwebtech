<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Genres extends Model
{
    protected $table = 'genres';

    protected $fillable = ['genre_name', 'genre_slug', 'status'];


	public $timestamps = false;



	public static function getGenresInfo($id,$field_name)
    {
		$genres_info = Genres::where('status','1')->where('id',$id)->first();

		if($genres_info)
		{
			return  $genres_info->$field_name;
		}
		else
		{
			return  '';
		}
	}

	public static function getGenresID($field_name)
    {
		$genres_info = Genres::where('genre_name',$field_name)->first();

		if($genres_info)
		{
			return  $genres_info->id;
		}
		else
		{
			return  '';
		}
	}

}
