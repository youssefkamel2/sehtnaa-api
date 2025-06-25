<?php

namespace Database\Seeders;

use App\Models\RequiredDocument;
use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Category;
use App\Models\Service;
use App\Models\ServiceRequirement;

class ProductionSeeder extends Seeder
{
    public function run()
    {
        $admin = User::where('email', 'superadmin@example.com')->first();
        if (!$admin) {
            throw new \Exception('Super admin user not found. Please seed users first.');
        }

        // Categories and their services
        $categories = [
            [
                'name' => [
                    'ar' => 'العلاج الطبيعي',
                    'en' => 'Physiotherapy',
                ],
                'description' => [
                    'ar' => 'خدمات العلاج الطبيعي والتأهيل المنزلي على يد متخصصين.',
                    'en' => 'Home physiotherapy and rehabilitation services by specialists.',
                ],
                'icon' => 'default.png',
                'is_active' => true,
                'is_multiple' => false,
                'order' => 1,
                'services' => [
                    [
                        'ar' => 'زيارة تقييم أولي',
                        'en' => 'Initial Assessment Visit',
                        'desc_ar' => 'زيارة تقييم أولية من أخصائي العلاج الطبيعي.',
                        'desc_en' => 'Initial assessment visit by a physiotherapist.',
                        'provider_type' => 'individual',
                        'price' => 250,
                    ],
                    [
                        'ar' => 'زيارة متابعة لمدة ٣٠ دقيقة',
                        'en' => 'Follow-up Visit (30 min)',
                        'desc_ar' => 'زيارة متابعة لمدة ٣٠ دقيقة.',
                        'desc_en' => 'Follow-up visit for 30 minutes.',
                        'provider_type' => 'individual',
                        'price' => 250,
                    ],
                    [
                        'ar' => 'زيارة متابعة لمدة ٤٥ دقيقة',
                        'en' => 'Follow-up Visit (45 min)',
                        'desc_ar' => 'زيارة متابعة لمدة ٤٥ دقيقة.',
                        'desc_en' => 'Follow-up visit for 45 minutes.',
                        'provider_type' => 'individual',
                        'price' => 350,
                    ],
                    [
                        'ar' => 'زيارة متابعة لمدة ٦٠ دقيقة',
                        'en' => 'Follow-up Visit (60 min)',
                        'desc_ar' => 'زيارة متابعة لمدة ٦٠ دقيقة.',
                        'desc_en' => 'Follow-up visit for 60 minutes.',
                        'provider_type' => 'individual',
                        'price' => 500,
                    ],
                    [
                        'ar' => 'التدريب على توازن الجسم',
                        'en' => 'Body Balance Training',
                        'desc_ar' => 'تدريب متخصص لتحسين توازن الجسم.',
                        'desc_en' => 'Specialized training to improve body balance.',
                        'provider_type' => 'individual',
                        'price' => 300,
                    ],
                    [
                        'ar' => 'إعادة التأهيل',
                        'en' => 'Rehabilitation',
                        'desc_ar' => 'جلسات إعادة التأهيل بعد الإصابات أو العمليات.',
                        'desc_en' => 'Rehabilitation sessions after injuries or surgeries.',
                        'provider_type' => 'individual',
                        'price' => 400,
                    ],
                    [
                        'ar' => 'تمارين تقوية ضعف العضلات',
                        'en' => 'Muscle Strengthening Exercises',
                        'desc_ar' => 'تمارين متخصصة لتقوية العضلات الضعيفة.',
                        'desc_en' => 'Specialized exercises to strengthen weak muscles.',
                        'provider_type' => 'individual',
                        'price' => 350,
                    ],
                    [
                        'ar' => 'رعاية المصابين بالأمراض العصبية العضلية',
                        'en' => 'Care for Neuromuscular Disease Patients',
                        'desc_ar' => 'رعاية متخصصة للمصابين بالأمراض العصبية العضلية.',
                        'desc_en' => 'Specialized care for neuromuscular disease patients.',
                        'provider_type' => 'individual',
                        'price' => 400,
                    ],
                    [
                        'ar' => 'العناية بالانزلاق الغضروفي وتقويم العمود الفقري',
                        'en' => 'Disc Herniation & Spine Care',
                        'desc_ar' => 'العناية بالانزلاق الغضروفي وتقويم العمود الفقري.',
                        'desc_en' => 'Care for disc herniation and spinal alignment.',
                        'provider_type' => 'individual',
                        'price' => 400,
                    ],
                    [
                        'ar' => 'الرعاية بعد الكسور والعمليات الجراحية',
                        'en' => 'Post-Fracture & Post-Surgery Care',
                        'desc_ar' => 'رعاية بعد الكسور والعمليات الجراحية.',
                        'desc_en' => 'Care after fractures and surgeries.',
                        'provider_type' => 'individual',
                        'price' => 400,
                    ],
                    [
                        'ar' => 'العناية بالركبة والمفاصل',
                        'en' => 'Knee & Joint Care',
                        'desc_ar' => 'العناية المتخصصة بالركبة والمفاصل.',
                        'desc_en' => 'Specialized care for knees and joints.',
                        'provider_type' => 'individual',
                        'price' => 350,
                    ],
                    [
                        'ar' => 'إعادة تأهيل الرياضيين',
                        'en' => 'Athlete Rehabilitation',
                        'desc_ar' => 'إعادة تأهيل الرياضيين بعد الإصابات.',
                        'desc_en' => 'Rehabilitation for athletes after injuries.',
                        'provider_type' => 'individual',
                        'price' => 500,
                    ],
                    [
                        'ar' => 'التدليك العلاجي للجسم (مساج)',
                        'en' => 'Therapeutic Body Massage',
                        'desc_ar' => 'جلسات تدليك علاجي للجسم.',
                        'desc_en' => 'Therapeutic body massage sessions.',
                        'provider_type' => 'individual',
                        'price' => 350,
                    ],
                ],
            ],
            [
                'name' => [
                    'ar' => 'الأطباء',
                    'en' => 'Doctors',
                ],
                'description' => [
                    'ar' => 'خدمات زيارات الأطباء في جميع التخصصات.',
                    'en' => 'Doctor home visit services in all specialties.',
                ],
                'icon' => 'default.png',
                'is_active' => true,
                'is_multiple' => false,
                'order' => 2,
                'services' => [
                    [ 'ar' => 'الأطفال', 'en' => 'Pediatrics', 'desc_ar' => 'استشارات وزيارات منزلية للأطفال.', 'desc_en' => 'Home consultations and visits for children.', 'provider_type' => 'individual', 'price' => 1200 ],
                    [ 'ar' => 'النساء والتوليد', 'en' => 'Obstetrics & Gynecology', 'desc_ar' => 'استشارات وزيارات منزلية للنساء والتوليد.', 'desc_en' => 'Home consultations and visits for obstetrics and gynecology.', 'provider_type' => 'individual', 'price' => 1200 ],
                    [ 'ar' => 'الباطنية', 'en' => 'Internal Medicine', 'desc_ar' => 'استشارات وزيارات منزلية للباطنة.', 'desc_en' => 'Home consultations and visits for internal medicine.', 'provider_type' => 'individual', 'price' => 1200 ],
                    [ 'ar' => 'الجراحة العامة', 'en' => 'General Surgery', 'desc_ar' => 'استشارات وزيارات منزلية للجراحة العامة.', 'desc_en' => 'Home consultations and visits for general surgery.', 'provider_type' => 'individual', 'price' => 1200 ],
                    [ 'ar' => 'جراحة المخ والأعصاب', 'en' => 'Neurosurgery', 'desc_ar' => 'استشارات وزيارات منزلية لجراحة المخ والأعصاب.', 'desc_en' => 'Home consultations and visits for neurosurgery.', 'provider_type' => 'individual', 'price' => 1200 ],
                    [ 'ar' => 'المخ والأعصاب', 'en' => 'Neurology', 'desc_ar' => 'استشارات وزيارات منزلية للمخ والأعصاب.', 'desc_en' => 'Home consultations and visits for neurology.', 'provider_type' => 'individual', 'price' => 1200 ],
                    [ 'ar' => 'القلب والصدر', 'en' => 'Cardiology & Chest', 'desc_ar' => 'استشارات وزيارات منزلية للقلب والصدر.', 'desc_en' => 'Home consultations and visits for cardiology and chest.', 'provider_type' => 'individual', 'price' => 1200 ],
                    [ 'ar' => 'الصدرية', 'en' => 'Pulmonology', 'desc_ar' => 'استشارات وزيارات منزلية للصدرية.', 'desc_en' => 'Home consultations and visits for pulmonology.', 'provider_type' => 'individual', 'price' => 1200 ],
                    [ 'ar' => 'الأنف والأذن والحنجرة', 'en' => 'ENT', 'desc_ar' => 'استشارات وزيارات منزلية للأنف والأذن والحنجرة.', 'desc_en' => 'Home consultations and visits for ENT.', 'provider_type' => 'individual', 'price' => 1200 ],
                    [ 'ar' => 'الجلدية', 'en' => 'Dermatology', 'desc_ar' => 'استشارات وزيارات منزلية للأمراض الجلدية.', 'desc_en' => 'Home consultations and visits for dermatology.', 'provider_type' => 'individual', 'price' => 1200 ],
                    [ 'ar' => 'المسالك البولية', 'en' => 'Urology', 'desc_ar' => 'استشارات وزيارات منزلية للمسالك البولية.', 'desc_en' => 'Home consultations and visits for urology.', 'provider_type' => 'individual', 'price' => 1200 ],
                    [ 'ar' => 'الأوعية الدموية', 'en' => 'Vascular', 'desc_ar' => 'استشارات وزيارات منزلية للأوعية الدموية.', 'desc_en' => 'Home consultations and visits for vascular.', 'provider_type' => 'individual', 'price' => 1200 ],
                    [ 'ar' => 'أمراض الجهاز الهضمي', 'en' => 'Gastroenterology', 'desc_ar' => 'استشارات وزيارات منزلية للجهاز الهضمي.', 'desc_en' => 'Home consultations and visits for gastroenterology.', 'provider_type' => 'individual', 'price' => 1200 ],
                    [ 'ar' => 'الغدد الصماء', 'en' => 'Endocrinology', 'desc_ar' => 'استشارات وزيارات منزلية للغدد الصماء.', 'desc_en' => 'Home consultations and visits for endocrinology.', 'provider_type' => 'individual', 'price' => 1200 ],
                    [ 'ar' => 'النفسية', 'en' => 'Psychiatry', 'desc_ar' => 'استشارات وزيارات منزلية للأمراض النفسية.', 'desc_en' => 'Home consultations and visits for psychiatry.', 'provider_type' => 'individual', 'price' => 1200 ],
                    [ 'ar' => 'الأسنان', 'en' => 'Dentistry', 'desc_ar' => 'استشارات وزيارات منزلية لطب الأسنان.', 'desc_en' => 'Home consultations and visits for dentistry.', 'provider_type' => 'individual', 'price' => 1200 ],
                ],
            ],
            [
                'name' => [
                    'ar' => 'الخدمات التمريضية',
                    'en' => 'Nursing Services',
                ],
                'description' => [
                    'ar' => 'خدمات تمريضية منزلية سريعة وممتدة.',
                    'en' => 'Home nursing services, both rapid and extended.',
                ],
                'icon' => 'default.png',
                'is_active' => true,
                'is_multiple' => true,
                'order' => 3,
                'services' => [
                    [ 'ar' => 'إعطاء الأدوية وقراءة العلامات الحيوية', 'en' => 'Medication Administration & Vital Signs', 'desc_ar' => 'إعطاء الأدوية وقراءة العلامات الحيوية.', 'desc_en' => 'Medication administration and vital signs monitoring.', 'provider_type' => 'individual', 'price' => 200 ],
                    [ 'ar' => 'إعطاء الحقن وتركيب أنبوب الحقن (الكانيولا)', 'en' => 'Injection & Cannula Placement', 'desc_ar' => 'إعطاء الحقن وتركيب الكانيولا.', 'desc_en' => 'Injection administration and cannula placement.', 'provider_type' => 'individual', 'price' => 200 ],
                    [ 'ar' => 'الغيار على الجروح', 'en' => 'Wound Dressing', 'desc_ar' => 'الغيار على الجروح.', 'desc_en' => 'Wound dressing.', 'provider_type' => 'individual', 'price' => 300 ],
                    [ 'ar' => 'العناية بالحروق وتضميدها', 'en' => 'Burn Care & Dressing', 'desc_ar' => 'العناية بالحروق وتضميدها.', 'desc_en' => 'Burn care and dressing.', 'provider_type' => 'individual', 'price' => 300 ],
                    [ 'ar' => 'العناية بأنبوب التغذية وتركيبه عبر الأنف', 'en' => 'Nasogastric Tube Care', 'desc_ar' => 'العناية بأنبوب التغذية وتركيبه عبر الأنف.', 'desc_en' => 'Care and placement of nasogastric tube.', 'provider_type' => 'individual', 'price' => 250 ],
                    [ 'ar' => 'العناية بأنبوب التغذية في البطن', 'en' => 'Gastrostomy Tube Care', 'desc_ar' => 'العناية بأنبوب التغذية في البطن.', 'desc_en' => 'Care for gastrostomy tube.', 'provider_type' => 'individual', 'price' => 300 ],
                    [ 'ar' => 'العناية بالأنبوب الرغامي (تريكستومي)', 'en' => 'Tracheostomy Care', 'desc_ar' => 'العناية بالأنبوب الرغامي (تريكستومي).', 'desc_en' => 'Tracheostomy tube care.', 'provider_type' => 'individual', 'price' => 400 ],
                    [ 'ar' => 'العناية بحقيبة فغر القولون (كولوستومي)', 'en' => 'Colostomy Bag Care', 'desc_ar' => 'العناية بحقيبة فغر القولون (كولوستومي).', 'desc_en' => 'Colostomy bag care.', 'provider_type' => 'individual', 'price' => 350 ],
                    [ 'ar' => 'العناية بالقدم السكري', 'en' => 'Diabetic Foot Care', 'desc_ar' => 'العناية بالقدم السكري.', 'desc_en' => 'Diabetic foot care.', 'provider_type' => 'individual', 'price' => 350 ],
                    [ 'ar' => 'العناية بالقرح السريرية وقرحة الفراش ومنعها', 'en' => 'Bed Sore & Pressure Ulcer Care', 'desc_ar' => 'العناية بالقرح السريرية وقرحة الفراش ومنعها.', 'desc_en' => 'Care and prevention of bed sores and pressure ulcers.', 'provider_type' => 'individual', 'price' => 300 ],
                    [ 'ar' => 'رعاية مرضى الجهاز التنفسي الصناعي', 'en' => 'Ventilator Patient Care', 'desc_ar' => 'رعاية مرضى الجهاز التنفسي الصناعي.', 'desc_en' => 'Care for ventilator patients.', 'provider_type' => 'individual', 'price' => 400 ],
                    [ 'ar' => 'رعاية مرضى الجلطات الدماغية', 'en' => 'Stroke Patient Care', 'desc_ar' => 'رعاية مرضى الجلطات الدماغية.', 'desc_en' => 'Care for stroke patients.', 'provider_type' => 'individual', 'price' => 400 ],
                    [ 'ar' => 'توعية وتثقيف', 'en' => 'Awareness & Education', 'desc_ar' => 'توعية وتثقيف للمرضى وأسرهم.', 'desc_en' => 'Awareness and education for patients and families.', 'provider_type' => 'individual', 'price' => 200 ],
                ],
            ],
            [
                'name' => [
                    'ar' => 'معامل التحاليل',
                    'en' => 'Laboratories',
                ],
                'description' => [
                    'ar' => 'خدمات التحاليل الطبية المنزلية.',
                    'en' => 'Home laboratory test services.',
                ],
                'icon' => 'default.png',
                'is_active' => true,
                'is_multiple' => false,
                'order' => 4,
                'services' => [
                    [ 'ar' => 'سحب عينات الدم المخبرية', 'en' => 'Blood Sample Collection', 'desc_ar' => 'سحب عينات الدم في المنزل.', 'desc_en' => 'Blood sample collection at home.', 'provider_type' => 'organizational', 'price' => 0 ],
                    [ 'ar' => 'الفحص الشامل', 'en' => 'Comprehensive Checkup', 'desc_ar' => 'فحص شامل لجميع المؤشرات الحيوية.', 'desc_en' => 'Comprehensive checkup for all vital indicators.', 'provider_type' => 'organizational', 'price' => 0 ],
                    [ 'ar' => 'فحص صورة الدم الكاملة', 'en' => 'Complete Blood Count', 'desc_ar' => 'فحص صورة الدم الكاملة.', 'desc_en' => 'Complete blood count test.', 'provider_type' => 'organizational', 'price' => 0 ],
                    [ 'ar' => 'فحوصات إنزيمات الكلى والكبد', 'en' => 'Kidney & Liver Enzymes', 'desc_ar' => 'فحوصات إنزيمات الكلى والكبد.', 'desc_en' => 'Kidney and liver enzyme tests.', 'provider_type' => 'organizational', 'price' => 0 ],
                    [ 'ar' => 'فحوصات نسب التخثر', 'en' => 'Coagulation Tests', 'desc_ar' => 'فحوصات نسب التخثر.', 'desc_en' => 'Coagulation tests.', 'provider_type' => 'organizational', 'price' => 0 ],
                    [ 'ar' => 'فحوصات الفيتامينات', 'en' => 'Vitamin Tests', 'desc_ar' => 'فحوصات الفيتامينات.', 'desc_en' => 'Vitamin tests.', 'provider_type' => 'organizational', 'price' => 0 ],
                    [ 'ar' => 'فحوصات التهاب المفاصل', 'en' => 'Arthritis Tests', 'desc_ar' => 'فحوصات التهاب المفاصل.', 'desc_en' => 'Arthritis tests.', 'provider_type' => 'organizational', 'price' => 0 ],
                    [ 'ar' => 'فحوصات حماية القلب', 'en' => 'Cardiac Protection Tests', 'desc_ar' => 'فحوصات حماية القلب.', 'desc_en' => 'Cardiac protection tests.', 'provider_type' => 'organizational', 'price' => 0 ],
                    [ 'ar' => 'فحوصات الغدة الدرقية', 'en' => 'Thyroid Tests', 'desc_ar' => 'فحوصات الغدة الدرقية.', 'desc_en' => 'Thyroid tests.', 'provider_type' => 'organizational', 'price' => 0 ],
                    [ 'ar' => 'فحوصات السكري', 'en' => 'Diabetes Tests', 'desc_ar' => 'فحوصات السكري.', 'desc_en' => 'Diabetes tests.', 'provider_type' => 'organizational', 'price' => 0 ],
                    [ 'ar' => 'فحوصات هشاشة العظام', 'en' => 'Osteoporosis Tests', 'desc_ar' => 'فحوصات هشاشة العظام.', 'desc_en' => 'Osteoporosis tests.', 'provider_type' => 'organizational', 'price' => 0 ],
                    [ 'ar' => 'فحوصات الحمل', 'en' => 'Pregnancy Tests', 'desc_ar' => 'فحوصات الحمل.', 'desc_en' => 'Pregnancy tests.', 'provider_type' => 'organizational', 'price' => 0 ],
                ],
            ],
            [
                'name' => [
                    'ar' => 'النظارات الطبية وسماعات الأذن',
                    'en' => 'Eyeglasses & Hearing Aids',
                ],
                'description' => [
                    'ar' => 'حجز مواعيد وتوصيل النظارات والسماعات الطبية.',
                    'en' => 'Book appointments and deliver eyeglasses and hearing aids.',
                ],
                'icon' => 'default.png',
                'is_active' => true,
                'is_multiple' => false,
                'order' => 5,
                'services' => [
                    [ 'ar' => 'النظارات الطبية وسماعات الأذن', 'en' => 'Eyeglasses & Hearing Aids', 'desc_ar' => 'توصيل النظارات والسماعات الطبية حسب الطلب.', 'desc_en' => 'Delivery of eyeglasses and hearing aids as requested.', 'provider_type' => 'organizational', 'price' => 0 ],
                ],
            ],
            [
                'name' => [
                    'ar' => 'الإسعاف',
                    'en' => 'Ambulance',
                ],
                'description' => [
                    'ar' => 'خدمة نقل المرضى بسيارة الإسعاف.',
                    'en' => 'Patient transfer service by ambulance.',
                ],
                'icon' => 'default.png',
                'is_active' => true,
                'is_multiple' => false,
                'order' => 6,
                'services' => [
                    [ 'ar' => 'الإسعاف', 'en' => 'Ambulance', 'desc_ar' => 'نقل المرضى بسيارة الإسعاف في جميع المناطق.', 'desc_en' => 'Patient transfer by ambulance in all areas.', 'provider_type' => 'organizational', 'price' => 0 ],
                ],
            ],
            [
                'name' => [
                    'ar' => 'التجميل',
                    'en' => 'Cosmetic Services',
                ],
                'description' => [
                    'ar' => 'جلسات تجميل للوجه والجسم في المنزل.',
                    'en' => 'Cosmetic sessions for face and body at home.',
                ],
                'icon' => 'default.png',
                'is_active' => true,
                'is_multiple' => false,
                'order' => 7,
                'services' => [
                    [ 'ar' => 'التجميل', 'en' => 'Cosmetic Services', 'desc_ar' => 'جلسات تجميل مختلفة للوجه والجسم.', 'desc_en' => 'Various cosmetic sessions for face and body.', 'provider_type' => 'organizational', 'price' => 0 ],
                ],
            ],
            [
                'name' => [
                    'ar' => 'باقات المتابعة الدورية للأمراض المزمنة',
                    'en' => 'Chronic Disease Follow-up Packages',
                ],
                'description' => [
                    'ar' => 'باقات مراجعات وتحاليل وأشعة وتوصيل علاج شهري.',
                    'en' => 'Packages for reviews, lab tests, imaging, and monthly medication delivery.',
                ],
                'icon' => 'default.png',
                'is_active' => true,
                'is_multiple' => false,
                'order' => 8,
                'services' => [
                    [ 'ar' => 'باقات المتابعة الدورية للأمراض المزمنة', 'en' => 'Chronic Disease Follow-up Packages', 'desc_ar' => 'باقات مراجعات وتحاليل وأشعة وتوصيل علاج شهري.', 'desc_en' => 'Packages for reviews, lab tests, imaging, and monthly medication delivery.', 'provider_type' => 'organizational', 'price' => 0 ],
                ],
            ],
        ];

        foreach ($categories as $catIndex => $catData) {
            $category = Category::create([
                'name' => [ 'ar' => $catData['name']['ar'], 'en' => $catData['name']['en'] ],
                'description' => [ 'ar' => $catData['description']['ar'], 'en' => $catData['description']['en'] ],
                'icon' => $catData['icon'],
                'is_active' => $catData['is_active'],
                'is_multiple' => $catData['is_multiple'],
                'order' => $catData['order'],
                'added_by' => $admin->id,
            ]);

            foreach ($catData['services'] as $srvIndex => $srvData) {
                $service = Service::create([
                    'name' => [ 'ar' => $srvData['ar'], 'en' => $srvData['en'] ],
                    'description' => [ 'ar' => $srvData['desc_ar'], 'en' => $srvData['desc_en'] ],
                    'provider_type' => $srvData['provider_type'],
                    'price' => $srvData['price'],
                    'icon' => 'default.png',
                    'cover_photo' => null,
                    'category_id' => $category->id,
                    'is_active' => true,
                    'added_by' => $admin->id,
                ]);

                // Add requirements if needed (example: patient name for all, file upload for labs, etc.)
                if ($category->name['en'] === 'Laboratories') {
                    ServiceRequirement::create([
                        'service_id' => $service->id,
                        'name' => [ 'ar' => 'ملف التحاليل', 'en' => 'Lab File' ],
                        'type' => 'file',
                    ]);
                }
                if ($category->name['en'] === 'Nursing Services') {
                    ServiceRequirement::create([
                        'service_id' => $service->id,
                        'name' => [ 'ar' => 'اسم المريض', 'en' => 'Patient Name' ],
                        'type' => 'input',
                    ]);
                }
            }
        }

        // Seed required documents for providers
        $requiredDocuments = [
            // Individual provider documents
            [
                'name' => [
                    'ar' => 'البطاقة الشخصية',
                    'en' => 'National ID',
                ],
                'provider_type' => 'individual',
            ],
            [
                'name' => [
                    'ar' => 'رخصة مزاولة المهنة',
                    'en' => 'Practice License',
                ],
                'provider_type' => 'individual',
            ],
            [
                'name' => [
                    'ar' => 'شهادة التخرج',
                    'en' => 'Graduation Certificate',
                ],
                'provider_type' => 'individual',
            ],
            [
                'name' => [
                    'ar' => 'شهادة خبرة',
                    'en' => 'Experience Certificate',
                ],
                'provider_type' => 'individual',
            ],
            // Organizational provider documents
            [
                'name' => [
                    'ar' => 'السجل التجاري',
                    'en' => 'Commercial Registration',
                ],
                'provider_type' => 'organizational',
            ],
            [
                'name' => [
                    'ar' => 'البطاقة الضريبية',
                    'en' => 'Tax Card',
                ],
                'provider_type' => 'organizational',
            ],
            [
                'name' => [
                    'ar' => 'رخصة مزاولة النشاط',
                    'en' => 'Activity License',
                ],
                'provider_type' => 'organizational',
            ],
            [
                'name' => [
                    'ar' => 'عقد مقر الشركة',
                    'en' => 'Company Headquarters Contract',
                ],
                'provider_type' => 'organizational',
            ],
        ];
        foreach ($requiredDocuments as $doc) {
            RequiredDocument::create($doc);
        }
    }
} 