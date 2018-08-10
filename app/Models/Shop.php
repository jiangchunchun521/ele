<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;

class Shop extends Model
{
    use Searchable;
    public $asYouType = true;

    /**
     * Get the indexable data array for the model.
     *
     * @return array
     */
    public function toSearchableArray()
    {
        $array = $this->only('id', 'shop_name', 'status');
        // Customize array...
        return $array;
    }

    //设置可修改字段
    public $fillable = ['shop_cate_id', 'shop_name', 'shop_img', 'shop_rating',
        'brand', 'on_time', 'fengniao', 'bao', 'piao', 'zhun', 'start_send', 'send_cost',
        'notice', 'discount', 'status'];

    //通过商家找店铺类型
    public function cate()
    {
        return $this->belongsTo(ShopCategory::class, 'shop_cate_id');
    }

    //通过商户找店铺
    public function user()
    {
        return $this->hasOne(User::class, "shop_id");
    }
}
