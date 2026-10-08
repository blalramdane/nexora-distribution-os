<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class EgyptGeographySeeder extends Seeder
{
    public function run(): void
    {
        $governorates = [
            ['EG01','القاهرة','Cairo'],['EG02','الاسكندرية','Alexandria'],['EG03','بورسعيد','Port Said'],
            ['EG04','السويس','Suez'],['EG11','دمياط','Damietta'],['EG12','الدقهلية','Dakahlia'],
            ['EG13','الشرقية','Sharkia'],['EG14','القليوبية','Kalyoubia'],['EG15','كفر الشيخ','Kafr El-Shikh'],
            ['EG16','الغربية','Gharbia'],['EG17','المنوفية','Menoufia'],['EG18','البحيرة','Behera'],
            ['EG19','الإسماعيلية','Ismailia'],['EG21','الجيزة','Giza'],['EG22','بنى سويف','Beni Suef'],
            ['EG23','الفيوم','Fayoum'],['EG24','المنيا','Menia'],['EG25','أسيوط','Assiut'],
            ['EG26','سوهاج','Suhag'],['EG27','قنا','Qena'],['EG28','أسوان','Aswan'],
            ['EG29','الأقصر','Luxor'],['EG31','البحر الأحمر','Red Sea'],['EG32','الوادى الجديد','New Valley'],
            ['EG33','مطروح','Matrouh'],['EG34','شمال سيناء','North Sinai'],['EG35','جنوب سيناء','South Sinai'],
        ];

        foreach ($governorates as [$code,$ar,$en]) {
            DB::table('governorates')->insertOrIgnore([
                'id'=>(string) Str::ulid(),
                'code'=>$code,
                'name_ar'=>$ar,
                'name_en'=>$en,
                'active'=>true,
                'created_at'=>now(),
                'updated_at'=>now(),
            ]);

            DB::table('governorates')
                ->where('code',$code)
                ->update([
                    'name_ar'=>$ar,
                    'name_en'=>$en,
                    'active'=>true,
                    'updated_at'=>now(),
                ]);
        }
    }
}
