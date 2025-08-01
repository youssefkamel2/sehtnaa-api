<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Category;
use App\Models\Service;
use App\Models\ServiceRequirement;

class ProductionSeeder extends Seeder
{
    public function run()
    {
        $admin = User::where('email', 'support@sehtnaa.com')->first();
        if (!$admin) {
            throw new \Exception('Super admin user not found. Please seed users first.');
        }

        // Truncate relevant tables (but NOT users)
        \DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        \DB::table('service_requirements')->truncate();
        \DB::table('services')->truncate();
        \DB::table('categories')->truncate();
        \DB::table('required_documents')->truncate();
        \DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        // Categories and their services
        $categories = [
            [
                'name' => [
                    'ar' => 'العلاج الطبيعي',
                    'en' => 'Physiotherapy',
                ],
                'description' => [
                    'ar' => 'خدمات العلاج الطبيعي والتأهيل المنزلي على يد متخصصين لمساعدتك على استعادة صحتك وحركتك بأمان وفعالية.',
                    'en' => 'Home physiotherapy and rehabilitation services by certified specialists to help you regain your health and mobility safely and effectively.',
                ],
                'icon' => 'category_icons/ikOEl4BMyeRaoc4iqudKNzGCHADg6ImcLNJ51Bw5.png',
                'is_active' => true,
                'is_multiple' => false,
                'order' => 1,
                'services' => [
                    [
                        'ar' => 'زيارة تقييم أولي',
                        'en' => 'Initial Assessment Visit',
                        'desc_ar' => 'ابدأ رحلة العلاج الطبيعي بزيارة تقييم شاملة من أخصائي معتمد لتحديد احتياجاتك ووضع خطة علاجية مخصصة.',
                        'desc_en' => 'Begin your physiotherapy journey with a comprehensive assessment visit by a certified specialist to identify your needs and create a personalized treatment plan.',
                        'provider_type' => 'individual',
                        'price' => 250,
                    ],
                    [
                        'ar' => 'زيارة متابعة لمدة ٣٠ دقيقة',
                        'en' => 'Follow-up Visit (30 min)',
                        'desc_ar' => 'جلسة متابعة لمدة ٣٠ دقيقة لمراقبة تقدمك وتعديل الخطة العلاجية حسب الحاجة.',
                        'desc_en' => 'A 30-minute follow-up session to monitor your progress and adjust your treatment plan as needed.',
                        'provider_type' => 'individual',
                        'price' => 250,
                    ],
                    [
                        'ar' => 'زيارة متابعة لمدة ٤٥ دقيقة',
                        'en' => 'Follow-up Visit (45 min)',
                        'desc_ar' => 'جلسة متابعة لمدة ٤٥ دقيقة لتعزيز نتائج العلاج وتحقيق أهدافك الصحية.',
                        'desc_en' => 'A 45-minute follow-up session to enhance your therapy results and achieve your health goals.',
                        'provider_type' => 'individual',
                        'price' => 350,
                    ],
                    [
                        'ar' => 'زيارة متابعة لمدة ٦٠ دقيقة',
                        'en' => 'Follow-up Visit (60 min)',
                        'desc_ar' => 'جلسة متابعة مكثفة لمدة ساعة كاملة لضمان أفضل رعاية وتأهيل.',
                        'desc_en' => 'An intensive 60-minute follow-up session to ensure optimal care and rehabilitation.',
                        'provider_type' => 'individual',
                        'price' => 500,
                    ],
                    [
                        'ar' => 'التدريب على توازن الجسم',
                        'en' => 'Body Balance Training',
                        'desc_ar' => 'تدريبات متخصصة لتحسين توازن الجسم والوقاية من السقوط.',
                        'desc_en' => 'Specialized exercises to improve body balance and prevent falls.',
                        'provider_type' => 'individual',
                        'price' => 300,
                    ],
                    [
                        'ar' => 'إعادة التأهيل',
                        'en' => 'Rehabilitation',
                        'desc_ar' => 'جلسات إعادة تأهيل شاملة بعد الإصابات أو العمليات الجراحية لاستعادة الحركة والوظائف.',
                        'desc_en' => 'Comprehensive rehabilitation sessions after injuries or surgeries to restore movement and function.',
                        'provider_type' => 'individual',
                        'price' => 400,
                    ],
                    [
                        'ar' => 'تمارين تقوية ضعف العضلات',
                        'en' => 'Muscle Strengthening Exercises',
                        'desc_ar' => 'تمارين مخصصة لتقوية العضلات الضعيفة وزيادة القدرة البدنية.',
                        'desc_en' => 'Targeted exercises to strengthen weak muscles and improve physical capacity.',
                        'provider_type' => 'individual',
                        'price' => 350,
                    ],
                    [
                        'ar' => 'رعاية المصابين بالأمراض العصبية العضلية',
                        'en' => 'Care for Neuromuscular Disease Patients',
                        'desc_ar' => 'رعاية متخصصة للمصابين بأمراض عصبية عضلية لتحسين جودة الحياة.',
                        'desc_en' => 'Specialized care for patients with neuromuscular diseases to improve quality of life.',
                        'provider_type' => 'individual',
                        'price' => 400,
                    ],
                    [
                        'ar' => 'العناية بالانزلاق الغضروفي وتقويم العمود الفقري',
                        'en' => 'Disc Herniation & Spine Care',
                        'desc_ar' => 'برامج علاجية للعناية بالانزلاق الغضروفي وتصحيح مشكلات العمود الفقري.',
                        'desc_en' => 'Therapeutic programs for disc herniation and spinal alignment issues.',
                        'provider_type' => 'individual',
                        'price' => 400,
                    ],
                    [
                        'ar' => 'الرعاية بعد الكسور والعمليات الجراحية',
                        'en' => 'Post-Fracture & Post-Surgery Care',
                        'desc_ar' => 'رعاية متكاملة بعد الكسور أو العمليات الجراحية لضمان الشفاء السليم.',
                        'desc_en' => 'Comprehensive care after fractures or surgeries to ensure proper healing.',
                        'provider_type' => 'individual',
                        'price' => 400,
                    ],
                    [
                        'ar' => 'العناية بالركبة والمفاصل',
                        'en' => 'Knee & Joint Care',
                        'desc_ar' => 'جلسات علاجية للعناية بالركبة والمفاصل وتخفيف الألم.',
                        'desc_en' => 'Therapeutic sessions for knee and joint care to relieve pain.',
                        'provider_type' => 'individual',
                        'price' => 350,
                    ],
                    [
                        'ar' => 'إعادة تأهيل الرياضيين',
                        'en' => 'Athlete Rehabilitation',
                        'desc_ar' => 'إعادة تأهيل متخصصة للرياضيين بعد الإصابات للعودة إلى النشاط بأمان.',
                        'desc_en' => 'Specialized rehabilitation for athletes after injuries to safely return to activity.',
                        'provider_type' => 'individual',
                        'price' => 500,
                    ],
                    [
                        'ar' => 'التدليك العلاجي للجسم (مساج)',
                        'en' => 'Therapeutic Body Massage',
                        'desc_ar' => 'جلسات تدليك علاجي للجسم لتحسين الدورة الدموية وتخفيف التوتر.',
                        'desc_en' => 'Therapeutic body massage sessions to improve circulation and relieve tension.',
                        'provider_type' => 'individual',
                        'price' => 350,
                    ],
                ],
            ],
            [
                'name' => [ 'ar' => 'الأطباء', 'en' => 'Doctors' ],
                'description' => [
                    'ar' => 'خدمات زيارات الأطباء في جميع التخصصات لتوفير الرعاية الطبية الشاملة في منزلك، مع استشارات دقيقة وتشخيصات متقدمة من نخبة الأطباء.',
                    'en' => 'Doctor home visit services in all specialties, providing comprehensive medical care at your home with expert consultations and advanced diagnostics from top physicians.',
                ],
                'icon' => 'category_icons/ikOEl4BMyeRaoc4iqudKNzGCHADg6ImcLNJ51Bw5.png',
                'is_active' => true,
                'is_multiple' => false,
                'order' => 2,
                'services' => [
                    [ 'ar' => 'الأطفال', 'en' => 'Pediatrics', 'desc_ar' => 'استشارات وزيارات منزلية للأطفال تشمل الفحوصات الدورية، علاج الأمراض الشائعة، ومتابعة النمو والتطور لضمان صحة طفلك في بيئة آمنة ومريحة.', 'desc_en' => 'Home consultations and visits for children, including regular checkups, treatment of common illnesses, and growth monitoring to ensure your child’s health in a safe and comfortable environment.', 'provider_type' => 'individual', 'price' => 1200 ],
                    [ 'ar' => 'النساء والتوليد', 'en' => 'Obstetrics & Gynecology', 'desc_ar' => 'رعاية شاملة للنساء تشمل متابعة الحمل، الفحوصات الدورية، والاستشارات النسائية في منزلك مع أطباء متخصصين.', 'desc_en' => 'Comprehensive women’s care including pregnancy follow-up, regular checkups, and gynecological consultations at home by specialized doctors.', 'provider_type' => 'individual', 'price' => 1200 ],
                    [ 'ar' => 'الباطنية', 'en' => 'Internal Medicine', 'desc_ar' => 'تشخيص وعلاج أمراض الباطنة المزمنة والحادة في المنزل مع متابعة دقيقة لحالتك الصحية.', 'desc_en' => 'Diagnosis and treatment of chronic and acute internal medicine conditions at home, with careful monitoring of your health status.', 'provider_type' => 'individual', 'price' => 1200 ],
                    [ 'ar' => 'الجراحة العامة', 'en' => 'General Surgery', 'desc_ar' => 'استشارات جراحية منزلية لتقييم الحالات الجراحية وتقديم النصائح الطبية قبل وبعد العمليات.', 'desc_en' => 'Home surgical consultations for assessment of surgical cases and medical advice before and after operations.', 'provider_type' => 'individual', 'price' => 1200 ],
                    [ 'ar' => 'جراحة المخ والأعصاب', 'en' => 'Neurosurgery', 'desc_ar' => 'استشارات منزلية متخصصة في جراحة المخ والأعصاب لتشخيص وعلاج الحالات العصبية المعقدة.', 'desc_en' => 'Specialized home consultations in neurosurgery for diagnosis and management of complex neurological conditions.', 'provider_type' => 'individual', 'price' => 1200 ],
                    [ 'ar' => 'المخ والأعصاب', 'en' => 'Neurology', 'desc_ar' => 'تشخيص وعلاج أمراض الجهاز العصبي في المنزل مع أطباء أعصاب ذوي خبرة.', 'desc_en' => 'Diagnosis and treatment of nervous system diseases at home by experienced neurologists.', 'provider_type' => 'individual', 'price' => 1200 ],
                    [ 'ar' => 'القلب والصدر', 'en' => 'Cardiology & Chest', 'desc_ar' => 'رعاية منزلية متخصصة لأمراض القلب والصدر تشمل الفحوصات، التشخيص، والمتابعة المستمرة.', 'desc_en' => 'Specialized home care for heart and chest diseases, including examinations, diagnosis, and ongoing follow-up.', 'provider_type' => 'individual', 'price' => 1200 ],
                    [ 'ar' => 'الصدرية', 'en' => 'Pulmonology', 'desc_ar' => 'استشارات منزلية لأمراض الجهاز التنفسي مع تقييم شامل وخطط علاجية مخصصة.', 'desc_en' => 'Home consultations for respiratory diseases with comprehensive assessment and personalized treatment plans.', 'provider_type' => 'individual', 'price' => 1200 ],
                    [ 'ar' => 'الأنف والأذن والحنجرة', 'en' => 'ENT', 'desc_ar' => 'تشخيص وعلاج أمراض الأنف والأذن والحنجرة في المنزل مع أحدث التقنيات الطبية.', 'desc_en' => 'Diagnosis and treatment of ear, nose, and throat diseases at home using the latest medical technologies.', 'provider_type' => 'individual', 'price' => 1200 ],
                    [ 'ar' => 'الجلدية', 'en' => 'Dermatology', 'desc_ar' => 'استشارات جلدية منزلية لعلاج الأمراض الجلدية، الحساسية، ومشاكل البشرة.', 'desc_en' => 'Home dermatology consultations for skin diseases, allergies, and skin care issues.', 'provider_type' => 'individual', 'price' => 1200 ],
                    [ 'ar' => 'المسالك البولية', 'en' => 'Urology', 'desc_ar' => 'رعاية منزلية متخصصة لأمراض المسالك البولية وتشخيص الحالات الحرجة.', 'desc_en' => 'Specialized home care for urological diseases and diagnosis of critical conditions.', 'provider_type' => 'individual', 'price' => 1200 ],
                    [ 'ar' => 'الأوعية الدموية', 'en' => 'Vascular', 'desc_ar' => 'استشارات منزلية لأمراض الأوعية الدموية مع تقييم شامل وخطط علاجية فعالة.', 'desc_en' => 'Home consultations for vascular diseases with comprehensive assessment and effective treatment plans.', 'provider_type' => 'individual', 'price' => 1200 ],
                    [ 'ar' => 'أمراض الجهاز الهضمي', 'en' => 'Gastroenterology', 'desc_ar' => 'تشخيص وعلاج أمراض الجهاز الهضمي في المنزل مع متابعة دقيقة للحالة.', 'desc_en' => 'Diagnosis and treatment of digestive system diseases at home with careful follow-up.', 'provider_type' => 'individual', 'price' => 1200 ],
                    [ 'ar' => 'الغدد الصماء', 'en' => 'Endocrinology', 'desc_ar' => 'رعاية منزلية متخصصة لأمراض الغدد الصماء مع خطط علاجية مخصصة.', 'desc_en' => 'Specialized home care for endocrine diseases with personalized treatment plans.', 'provider_type' => 'individual', 'price' => 1200 ],
                    [ 'ar' => 'النفسية', 'en' => 'Psychiatry', 'desc_ar' => 'استشارات نفسية منزلية لدعم الصحة النفسية والعقلية.', 'desc_en' => 'Home psychiatric consultations to support mental and emotional health.', 'provider_type' => 'individual', 'price' => 1200 ],
                    [ 'ar' => 'الأسنان', 'en' => 'Dentistry', 'desc_ar' => 'خدمات طب الأسنان المنزلية تشمل الفحوصات، العلاجات، وتبييض الأسنان.', 'desc_en' => 'Home dental services including checkups, treatments, and teeth whitening.', 'provider_type' => 'individual', 'price' => 1200 ],
                ],
            ],
            [
                'name' => [ 'ar' => 'الخدمات التمريضية', 'en' => 'Nursing Services' ],
                'description' => [
                    'ar' => 'خدمات تمريضية منزلية سريعة وممتدة تشمل الرعاية الطبية اليومية، العناية بالجروح، وإدارة الحالات الحرجة على مدار الساعة.',
                    'en' => 'Comprehensive home nursing services, both rapid and extended, including daily medical care, wound management, and 24/7 critical case support.',
                ],
                'icon' => 'category_icons/ikOEl4BMyeRaoc4iqudKNzGCHADg6ImcLNJ51Bw5.png',
                'is_active' => true,
                'is_multiple' => true,
                'order' => 3,
                'services' => [
                    [ 'ar' => 'إعطاء الأدوية وقراءة العلامات الحيوية', 'en' => 'Medication Administration & Vital Signs', 'desc_ar' => 'خدمة إعطاء الأدوية وقراءة العلامات الحيوية بدقة لضمان استقرار الحالة الصحية للمريض في المنزل.', 'desc_en' => 'Medication administration and accurate monitoring of vital signs to ensure patient stability at home.', 'provider_type' => 'individual', 'price' => 200 ],
                    [ 'ar' => 'إعطاء الحقن وتركيب أنبوب الحقن (الكانيولا)', 'en' => 'Injection & Cannula Placement', 'desc_ar' => 'خدمة إعطاء الحقن وتركيب الكانيولا باحترافية لتسهيل العلاج المنزلي.', 'desc_en' => 'Professional injection administration and cannula placement to facilitate home treatment.', 'provider_type' => 'individual', 'price' => 200 ],
                    [ 'ar' => 'الغيار على الجروح', 'en' => 'Wound Dressing', 'desc_ar' => 'تغيير الغيارات على الجروح باستخدام مواد طبية معقمة لضمان الشفاء السريع.', 'desc_en' => 'Wound dressing using sterile medical materials to ensure fast healing.', 'provider_type' => 'individual', 'price' => 300 ],
                    [ 'ar' => 'العناية بالحروق وتضميدها', 'en' => 'Burn Care & Dressing', 'desc_ar' => 'رعاية متخصصة للحروق وتضميدها لتقليل الألم وتسريع الشفاء.', 'desc_en' => 'Specialized burn care and dressing to reduce pain and speed up recovery.', 'provider_type' => 'individual', 'price' => 300 ],
                    [ 'ar' => 'العناية بأنبوب التغذية وتركيبه عبر الأنف', 'en' => 'Nasogastric Tube Care', 'desc_ar' => 'تركيب والعناية بأنبوب التغذية عبر الأنف لضمان التغذية السليمة للمرضى.', 'desc_en' => 'Placement and care of nasogastric tube to ensure proper nutrition for patients.', 'provider_type' => 'individual', 'price' => 250 ],
                    [ 'ar' => 'العناية بأنبوب التغذية في البطن', 'en' => 'Gastrostomy Tube Care', 'desc_ar' => 'العناية بأنبوب التغذية في البطن لضمان التغذية المستمرة والآمنة.', 'desc_en' => 'Care for gastrostomy tube to ensure continuous and safe nutrition.', 'provider_type' => 'individual', 'price' => 300 ],
                    [ 'ar' => 'العناية بالأنبوب الرغامي (تريكستومي)', 'en' => 'Tracheostomy Care', 'desc_ar' => 'رعاية شاملة للأنبوب الرغامي (تريكستومي) للحفاظ على مجرى التنفس.', 'desc_en' => 'Comprehensive care for tracheostomy tube to maintain airway patency.', 'provider_type' => 'individual', 'price' => 400 ],
                    [ 'ar' => 'العناية بحقيبة فغر القولون (كولوستومي)', 'en' => 'Colostomy Bag Care', 'desc_ar' => 'العناية بحقيبة فغر القولون (كولوستومي) لضمان النظافة والراحة.', 'desc_en' => 'Colostomy bag care to ensure cleanliness and comfort.', 'provider_type' => 'individual', 'price' => 350 ],
                    [ 'ar' => 'العناية بالقدم السكري', 'en' => 'Diabetic Foot Care', 'desc_ar' => 'رعاية متخصصة للقدم السكري للوقاية من المضاعفات وتعزيز الشفاء.', 'desc_en' => 'Specialized diabetic foot care to prevent complications and promote healing.', 'provider_type' => 'individual', 'price' => 350 ],
                    [ 'ar' => 'العناية بالقرح السريرية وقرحة الفراش ومنعها', 'en' => 'Bed Sore & Pressure Ulcer Care', 'desc_ar' => 'رعاية شاملة للقرح السريرية وقرحة الفراش مع خطط وقائية وعلاجية فعالة.', 'desc_en' => 'Comprehensive care for bed sores and pressure ulcers with effective preventive and therapeutic plans.', 'provider_type' => 'individual', 'price' => 300 ],
                    [ 'ar' => 'رعاية مرضى الجهاز التنفسي الصناعي', 'en' => 'Ventilator Patient Care', 'desc_ar' => 'رعاية متخصصة لمرضى الجهاز التنفسي الصناعي لضمان استمرارية التنفس والدعم الطبي.', 'desc_en' => 'Specialized care for ventilator patients to ensure continuous breathing and medical support.', 'provider_type' => 'individual', 'price' => 400 ],
                    [ 'ar' => 'رعاية مرضى الجلطات الدماغية', 'en' => 'Stroke Patient Care', 'desc_ar' => 'رعاية شاملة لمرضى الجلطات الدماغية تشمل التأهيل والدعم النفسي.', 'desc_en' => 'Comprehensive care for stroke patients including rehabilitation and psychological support.', 'provider_type' => 'individual', 'price' => 400 ],
                    [ 'ar' => 'توعية وتثقيف', 'en' => 'Awareness & Education', 'desc_ar' => 'جلسات توعية وتثقيف للمرضى وأسرهم حول الرعاية الصحية المنزلية.', 'desc_en' => 'Awareness and education sessions for patients and their families about home healthcare.', 'provider_type' => 'individual', 'price' => 200 ],
                ],
            ],
            [
                'name' => [ 'ar' => 'معامل التحاليل', 'en' => 'Laboratories' ],
                'description' => [
                    'ar' => 'خدمات التحاليل الطبية المنزلية تشمل جميع أنواع الفحوصات المخبرية مع سحب العينات من المنزل وتوصيل النتائج بسرعة وسرية.',
                    'en' => 'Home laboratory test services covering all types of lab tests, with sample collection at home and fast, confidential results delivery.',
                ],
                'icon' => 'category_icons/ikOEl4BMyeRaoc4iqudKNzGCHADg6ImcLNJ51Bw5.png',
                'is_active' => true,
                'is_multiple' => false,
                'order' => 4,
                'services' => [
                    [ 'ar' => 'سحب عينات الدم المخبرية', 'en' => 'Blood Sample Collection', 'desc_ar' => 'خدمة سحب عينات الدم من المنزل باستخدام أدوات معقمة وطاقم طبي محترف.', 'desc_en' => 'Blood sample collection at home using sterile tools and a professional medical team.', 'provider_type' => 'organizational', 'price' => 0 ],
                    [ 'ar' => 'الفحص الشامل', 'en' => 'Comprehensive Checkup', 'desc_ar' => 'فحص شامل لجميع المؤشرات الحيوية والاختبارات المخبرية لضمان صحة كاملة.', 'desc_en' => 'Comprehensive checkup for all vital indicators and lab tests to ensure complete health.', 'provider_type' => 'organizational', 'price' => 0 ],
                    [ 'ar' => 'فحص صورة الدم الكاملة', 'en' => 'Complete Blood Count', 'desc_ar' => 'تحليل صورة الدم الكاملة للكشف عن أي مشاكل صحية مبكرًا.', 'desc_en' => 'Complete blood count test to detect any health issues early.', 'provider_type' => 'organizational', 'price' => 0 ],
                    [ 'ar' => 'فحوصات إنزيمات الكلى والكبد', 'en' => 'Kidney & Liver Enzymes', 'desc_ar' => 'فحوصات متخصصة لإنزيمات الكلى والكبد لمتابعة وظائف الأعضاء الحيوية.', 'desc_en' => 'Specialized tests for kidney and liver enzymes to monitor vital organ functions.', 'provider_type' => 'organizational', 'price' => 0 ],
                    [ 'ar' => 'فحوصات نسب التخثر', 'en' => 'Coagulation Tests', 'desc_ar' => 'اختبارات نسب التخثر للكشف عن مشاكل النزيف أو التجلط.', 'desc_en' => 'Coagulation tests to detect bleeding or clotting disorders.', 'provider_type' => 'organizational', 'price' => 0 ],
                    [ 'ar' => 'فحوصات الفيتامينات', 'en' => 'Vitamin Tests', 'desc_ar' => 'تحليل مستويات الفيتامينات في الجسم لتحديد أي نقص أو زيادة.', 'desc_en' => 'Analysis of vitamin levels in the body to identify any deficiencies or excesses.', 'provider_type' => 'organizational', 'price' => 0 ],
                    [ 'ar' => 'فحوصات التهاب المفاصل', 'en' => 'Arthritis Tests', 'desc_ar' => 'فحوصات متخصصة للكشف عن التهابات المفاصل وتحديد العلاج المناسب.', 'desc_en' => 'Specialized tests to detect arthritis and determine the appropriate treatment.', 'provider_type' => 'organizational', 'price' => 0 ],
                    [ 'ar' => 'فحوصات حماية القلب', 'en' => 'Cardiac Protection Tests', 'desc_ar' => 'اختبارات لحماية القلب والكشف المبكر عن أمراض القلب.', 'desc_en' => 'Tests for cardiac protection and early detection of heart diseases.', 'provider_type' => 'organizational', 'price' => 0 ],
                    [ 'ar' => 'فحوصات الغدة الدرقية', 'en' => 'Thyroid Tests', 'desc_ar' => 'تحليل وظائف الغدة الدرقية لتشخيص أي اضطرابات هرمونية.', 'desc_en' => 'Thyroid function tests to diagnose any hormonal disorders.', 'provider_type' => 'organizational', 'price' => 0 ],
                    [ 'ar' => 'فحوصات السكري', 'en' => 'Diabetes Tests', 'desc_ar' => 'اختبارات متخصصة لمتابعة مستويات السكر في الدم وإدارة مرض السكري.', 'desc_en' => 'Specialized tests to monitor blood sugar levels and manage diabetes.', 'provider_type' => 'organizational', 'price' => 0 ],
                    [ 'ar' => 'فحوصات هشاشة العظام', 'en' => 'Osteoporosis Tests', 'desc_ar' => 'تحليل كثافة العظام للكشف المبكر عن هشاشة العظام.', 'desc_en' => 'Bone density analysis for early detection of osteoporosis.', 'provider_type' => 'organizational', 'price' => 0 ],
                    [ 'ar' => 'فحوصات الحمل', 'en' => 'Pregnancy Tests', 'desc_ar' => 'اختبارات الحمل المنزلية الدقيقة لتأكيد الحمل ومتابعة صحة الأم.', 'desc_en' => 'Accurate home pregnancy tests to confirm pregnancy and monitor maternal health.', 'provider_type' => 'organizational', 'price' => 0 ],
                ],
            ],
            [
                'name' => [ 'ar' => 'النظارات الطبية وسماعات الأذن', 'en' => 'Eyeglasses & Hearing Aids' ],
                'description' => [
                    'ar' => 'خدمة حجز مواعيد مع أطباء العيون والسمعيات وتوصيل النظارات والسماعات الطبية إلى باب منزلك بسهولة وراحة.',
                    'en' => 'Book appointments with ophthalmologists and audiologists, and have eyeglasses and hearing aids delivered to your doorstep with ease and comfort.',
                ],
                'icon' => 'category_icons/ikOEl4BMyeRaoc4iqudKNzGCHADg6ImcLNJ51Bw5.png',
                'is_active' => true,
                'is_multiple' => false,
                'order' => 5,
                'services' => [
                    [ 'ar' => 'النظارات الطبية وسماعات الأذن', 'en' => 'Eyeglasses & Hearing Aids', 'desc_ar' => 'خدمة متكاملة لتوصيل النظارات الطبية وسماعات الأذن حسب وصف الطبيب مباشرة إلى منزلك.', 'desc_en' => 'Comprehensive service for delivering prescription eyeglasses and hearing aids as prescribed by your doctor directly to your home.', 'provider_type' => 'organizational', 'price' => 0 ],
                ],
            ],
            [
                'name' => [ 'ar' => 'الإسعاف', 'en' => 'Ambulance' ],
                'description' => [
                    'ar' => 'خدمة نقل المرضى بسيارة الإسعاف المجهزة بأحدث الأجهزة الطبية لتوفير رعاية طارئة وسريعة في جميع مناطق الجمهورية.',
                    'en' => 'Patient transfer service by ambulance equipped with the latest medical devices to provide fast and emergency care across all regions.',
                ],
                'icon' => 'category_icons/ikOEl4BMyeRaoc4iqudKNzGCHADg6ImcLNJ51Bw5.png',
                'is_active' => true,
                'is_multiple' => false,
                'order' => 6,
                'services' => [
                    [ 'ar' => 'الإسعاف', 'en' => 'Ambulance', 'desc_ar' => 'خدمة نقل المرضى بسيارة إسعاف مجهزة وفريق طبي محترف لضمان سلامتك أثناء النقل.', 'desc_en' => 'Patient transfer by a fully equipped ambulance and a professional medical team to ensure your safety during transport.', 'provider_type' => 'organizational', 'price' => 0 ],
                ],
            ],
            [
                'name' => [ 'ar' => 'التجميل', 'en' => 'Cosmetic Services' ],
                'description' => [
                    'ar' => 'جلسات تجميل متخصصة للوجه والجسم في المنزل باستخدام أحدث التقنيات والمنتجات الطبية الآمنة.',
                    'en' => 'Specialized cosmetic sessions for face and body at home using the latest technologies and safe medical products.',
                ],
                'icon' => 'category_icons/ikOEl4BMyeRaoc4iqudKNzGCHADg6ImcLNJ51Bw5.png',
                'is_active' => true,
                'is_multiple' => false,
                'order' => 7,
                'services' => [
                    [ 'ar' => 'التجميل', 'en' => 'Cosmetic Services', 'desc_ar' => 'جلسات تجميل متنوعة تشمل العناية بالبشرة، إزالة الشعر، وتجميل الجسم في راحة منزلك.', 'desc_en' => 'A variety of cosmetic sessions including skin care, hair removal, and body treatments in the comfort of your home.', 'provider_type' => 'organizational', 'price' => 0 ],
                ],
            ],
            [
                'name' => [ 'ar' => 'باقات المتابعة الدورية للأمراض المزمنة', 'en' => 'Chronic Disease Follow-up Packages' ],
                'description' => [
                    'ar' => 'باقات شاملة لمتابعة الأمراض المزمنة تشمل الزيارات الدورية، التحاليل، الأشعة، وتوصيل العلاج الشهري لضمان استقرار الحالة الصحية.',
                    'en' => 'Comprehensive packages for chronic disease follow-up including regular visits, lab tests, imaging, and monthly medication delivery to ensure stable health.',
                ],
                'icon' => 'category_icons/ikOEl4BMyeRaoc4iqudKNzGCHADg6ImcLNJ51Bw5.png',
                'is_active' => true,
                'is_multiple' => false,
                'order' => 8,
                'services' => [
                    [ 'ar' => 'باقات المتابعة الدورية للأمراض المزمنة', 'en' => 'Chronic Disease Follow-up Packages', 'desc_ar' => 'باقات متابعة دورية مصممة خصيصًا لمرضى الأمراض المزمنة لضمان الرعاية المستمرة والراحة في المنزل.', 'desc_en' => 'Regular follow-up packages specially designed for chronic disease patients to ensure continuous care and comfort at home.', 'provider_type' => 'organizational', 'price' => 0 ],
                ],
            ],
        ];

        foreach ($categories as $catIndex => $catData) {
            $category = Category::create([
                'name' => [ 'ar' => $catData['name']['ar'], 'en' => $catData['name']['en'] ],
                'description' => [ 'ar' => $catData['description']['ar'], 'en' => $catData['description']['en'] ],
                'icon' => 'category_icons/ikOEl4BMyeRaoc4iqudKNzGCHADg6ImcLNJ51Bw5.png',
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
                    'icon' => 'service_icons/Mvg50P7208wFNENwZs2d0hX4u6fH7N7HvYByDqUo.png',
                    'cover_photo' => 'service_covers/default.png',
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
                'name' => 'National ID',
                'provider_type' => 'individual',
            ],
            [
                'name' => 'Practice License',
                'provider_type' => 'individual',
            ],
            [
                'name' => 'Graduation Certificate',
                'provider_type' => 'individual',
            ],
            [
                'name' => 'Experience Certificate',
                'provider_type' => 'individual',
            ],
            // Organizational provider documents
            [
                'name' => 'Commercial Registration',
                'provider_type' => 'organizational',
            ],
            [
                'name' => 'Tax Card',
                'provider_type' => 'organizational',
            ],
            [
                'name' => 'Activity License',
                'provider_type' => 'organizational',
            ],
            [
                'name' => 'Company Headquarters Contract',
                'provider_type' => 'organizational',
            ],
        ];
        foreach ($requiredDocuments as $doc) {
            \App\Models\RequiredDocument::create([
                'name' => $doc['name'],
                'provider_type' => $doc['provider_type'],
            ]);
        }
    }
} 