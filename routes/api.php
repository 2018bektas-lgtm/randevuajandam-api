<?php

use App\Http\Controllers\Api\DoctorAuthApiController;
use App\Http\Controllers\Api\DoctorContentApiController;
use App\Http\Controllers\Api\DoctorEducationApiController;
use App\Http\Controllers\Api\DoctorFinansApiController;
use App\Http\Controllers\Api\DoctorPanelApiController;
use App\Http\Controllers\Api\PublicClinicSiteController;
use App\Http\Controllers\Api\PublicDoctorSiteController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Randevu Ajandam API (ayrı süreç, aynı DB)
|--------------------------------------------------------------------------
*/

// Public doctor-site (X-Api-Key + X-Api-Secret)
Route::prefix('v1/public')
    ->middleware(['doctor.site.key', 'throttle:60,1'])
    ->group(function () {
        // Tek istekte profil + içerik + hizmet (hekim sitesi hızı)
        Route::get('/bootstrap', [PublicDoctorSiteController::class, 'bootstrap']);
        Route::get('/profile', [PublicDoctorSiteController::class, 'profile']);
        Route::get('/services', [PublicDoctorSiteController::class, 'services']);
        Route::get('/site-content', [PublicDoctorSiteController::class, 'siteContent']);
        Route::get('/educations', [PublicDoctorSiteController::class, 'educations']);
        Route::post('/educations/apply', [PublicDoctorSiteController::class, 'storeEducationApplication'])->middleware('throttle:10,1');
        Route::get('/educations/{slugOrId}', [PublicDoctorSiteController::class, 'educationShow']);
        Route::get('/slots', [PublicDoctorSiteController::class, 'slots']);
        Route::post('/otp/send', [PublicDoctorSiteController::class, 'sendOtp'])->middleware('throttle:8,1');
        Route::post('/otp/verify', [PublicDoctorSiteController::class, 'verifyOtp'])->middleware('throttle:15,1');
        Route::post('/appointments', [PublicDoctorSiteController::class, 'storeAppointment'])->middleware('throttle:10,1');
    });

// Public clinic-site (Kurumsal paket — klinik API key)
Route::prefix('v1/public/clinic')
    ->middleware(['clinic.site.key', 'throttle:60,1'])
    ->group(function () {
        Route::get('/profile', [PublicClinicSiteController::class, 'profile']);
        Route::get('/doctors', [PublicClinicSiteController::class, 'doctors']);
        Route::get('/doctors/{idOrSlug}', [PublicClinicSiteController::class, 'doctorShow']);
        Route::get('/services', [PublicClinicSiteController::class, 'services']);
        Route::get('/site-content', [PublicClinicSiteController::class, 'siteContent']);
        Route::get('/slots', [PublicClinicSiteController::class, 'slots']);
        Route::post('/otp/send', [PublicClinicSiteController::class, 'sendOtp'])->middleware('throttle:8,1');
        Route::post('/otp/verify', [PublicClinicSiteController::class, 'verifyOtp'])->middleware('throttle:15,1');
        Route::post('/appointments', [PublicClinicSiteController::class, 'storeAppointment'])->middleware('throttle:10,1');
    });

Route::prefix('v1/public/manage')
    ->middleware('throttle:30,1')
    ->group(function () {
        Route::get('/{token}', [PublicDoctorSiteController::class, 'showByToken']);
        Route::post('/{token}/cancel', [PublicDoctorSiteController::class, 'cancelByToken']);
    });

/**
 * Shared hekim panel endpoints (randevu, içerik, finans…).
 * Used by both doctor-site and clinic-site doctor panels.
 */
$registerDoctorPanelRoutes = function (): void {
    Route::get('/auth/me', [DoctorAuthApiController::class, 'me']);
    Route::post('/auth/logout', [DoctorAuthApiController::class, 'logout']);

    // Temel panel (tüm web_sitesi paketlerinde)
    Route::get('/dashboard', [DoctorPanelApiController::class, 'dashboard']);
    Route::put('/profile', [DoctorPanelApiController::class, 'updateProfile']);
    Route::post('/profile', [DoctorPanelApiController::class, 'updateProfile']); // multipart foto
    Route::put('/password', [DoctorPanelApiController::class, 'updatePassword']);

    Route::middleware('doctor.paket:hakkimda')->group(function () {
        Route::put('/hakkimda', [DoctorPanelApiController::class, 'updateAbout']);
        Route::post('/hakkimda', [DoctorPanelApiController::class, 'updateAbout']);
    });

    Route::get('/randevu-ayarlari', [DoctorPanelApiController::class, 'randevuAyarlari']);
    Route::put('/randevu-ayarlari', [DoctorPanelApiController::class, 'updateRandevuAyarlari']);
    Route::put('/calisma-saatleri', [DoctorPanelApiController::class, 'updateCalismaSaatleri']);

    Route::get('/randevular', [DoctorPanelApiController::class, 'randevular']);
    Route::get('/takvim/events', [DoctorPanelApiController::class, 'calendarEvents']);
    Route::post('/takvim/periyot', [DoctorPanelApiController::class, 'updatePeriod']);
    Route::post('/randevular', [DoctorPanelApiController::class, 'storeRandevu']);
    Route::post('/randevular/misafir', [DoctorPanelApiController::class, 'storeRandevuMisafir']);
    Route::delete('/randevular/{id}', [DoctorPanelApiController::class, 'destroyRandevu']);
    Route::put('/randevular/{id}/durum', [DoctorPanelApiController::class, 'updateRandevuDurum']);
    Route::put('/randevular/{id}', [DoctorPanelApiController::class, 'updateRandevu']);
    Route::post('/randevular/{id}/guncelle', [DoctorPanelApiController::class, 'updateRandevu']);
    Route::put('/randevular/{id}/reschedule', [DoctorPanelApiController::class, 'reschedule']);
    Route::post('/randevular/{id}/reschedule', [DoctorPanelApiController::class, 'reschedule']);

    Route::get('/hastalar/ara', [DoctorPanelApiController::class, 'searchHastalar']);
    Route::get('/hastalar', [DoctorPanelApiController::class, 'patients']);
    Route::get('/hastalar/{id}', [DoctorPanelApiController::class, 'showHasta']);
    Route::post('/hastalar', [DoctorPanelApiController::class, 'storeHasta']);
    Route::put('/hastalar/{id}', [DoctorPanelApiController::class, 'updateHasta']);
    Route::delete('/hastalar/{id}', [DoctorPanelApiController::class, 'destroyHasta']);

    Route::get('/izinler', [DoctorPanelApiController::class, 'leaves']);
    Route::post('/izinler', [DoctorPanelApiController::class, 'storeLeave']);
    Route::delete('/izinler/{id}', [DoctorPanelApiController::class, 'destroyLeave']);

    Route::get('/hizli-kapat/slots', [DoctorPanelApiController::class, 'quickCloseSlots']);
    Route::post('/hizli-kapat', [DoctorPanelApiController::class, 'quickCloseSave']);

    Route::get('/hizmetler', [DoctorPanelApiController::class, 'hizmetler']);
    Route::post('/hizmetler', [DoctorPanelApiController::class, 'storeHizmet']);
    Route::put('/hizmetler/{id}', [DoctorPanelApiController::class, 'updateHizmet']);
    Route::post('/hizmetler/{id}', [DoctorPanelApiController::class, 'updateHizmet']); // multipart görsel
    Route::delete('/hizmetler/{id}', [DoctorPanelApiController::class, 'destroyHizmet']);

    Route::get('/site-icerik', [DoctorPanelApiController::class, 'siteIcerik']);
    Route::get('/branslar', [DoctorPanelApiController::class, 'branslar']);
    Route::get('/web-sitesi', [DoctorPanelApiController::class, 'websiteInfo']);
    Route::post('/web-sitesi', [DoctorPanelApiController::class, 'websiteSetup']);
    Route::post('/web-sitesi/api-anahtari', [DoctorPanelApiController::class, 'regenerateApiKeys']);

    Route::middleware('doctor.paket:blog')->group(function () {
        Route::get('/bloglar', [DoctorContentApiController::class, 'blogs']);
        Route::post('/bloglar', [DoctorContentApiController::class, 'storeBlog']);
        Route::post('/bloglar/{id}', [DoctorContentApiController::class, 'updateBlog']);
        Route::put('/bloglar/{id}', [DoctorContentApiController::class, 'updateBlog']);
        Route::delete('/bloglar/{id}', [DoctorContentApiController::class, 'destroyBlog']);
    });

    Route::middleware('doctor.paket:faq')->group(function () {
        Route::get('/faqs', [DoctorContentApiController::class, 'faqs']);
        Route::post('/faqs', [DoctorContentApiController::class, 'storeFaq']);
        Route::put('/faqs/{id}', [DoctorContentApiController::class, 'updateFaq']);
        Route::delete('/faqs/{id}', [DoctorContentApiController::class, 'destroyFaq']);
        Route::post('/faqs/{id}/toggle', [DoctorContentApiController::class, 'toggleFaq']);
    });

    Route::middleware('doctor.paket:galeri')->group(function () {
        Route::get('/galeri', [DoctorContentApiController::class, 'gallery']);
        Route::post('/galeri', [DoctorContentApiController::class, 'storeGallery']);
        Route::post('/galeri/sirala', [DoctorContentApiController::class, 'sortGallery']);
        Route::put('/galeri/{id}', [DoctorContentApiController::class, 'updateGallery']);
        Route::post('/galeri/{id}', [DoctorContentApiController::class, 'updateGallery']);
        Route::delete('/galeri/{id}', [DoctorContentApiController::class, 'destroyGallery']);
    });

    Route::middleware('doctor.paket:yorum')->group(function () {
        Route::get('/yorumlar', [DoctorContentApiController::class, 'reviews']);
        Route::post('/yorumlar/{id}/yanit', [DoctorContentApiController::class, 'replyReview']);
        Route::put('/yorumlar/{id}/durum', [DoctorContentApiController::class, 'moderateReview']);
    });

    // Eğitimler (kurs/webinar) — ana site hekim paneli ile aynı
    Route::middleware('doctor.paket:egitimler')->group(function () {
        Route::get('/egitimler', [DoctorEducationApiController::class, 'index']);
        Route::post('/egitimler', [DoctorEducationApiController::class, 'store']);
        Route::get('/egitimler/basvurular', [DoctorEducationApiController::class, 'applicationsAll']);
        Route::get('/egitimler/{id}', [DoctorEducationApiController::class, 'show'])->whereNumber('id');
        Route::put('/egitimler/{id}', [DoctorEducationApiController::class, 'update'])->whereNumber('id');
        Route::post('/egitimler/{id}', [DoctorEducationApiController::class, 'update'])->whereNumber('id'); // multipart
        Route::delete('/egitimler/{id}', [DoctorEducationApiController::class, 'destroy'])->whereNumber('id');
        Route::get('/egitimler/{id}/basvurular', [DoctorEducationApiController::class, 'applications'])->whereNumber('id');
        Route::post('/egitimler/{id}/basvurular/{basvuruId}/durum', [DoctorEducationApiController::class, 'updateApplicationStatus'])
            ->whereNumber('id')->whereNumber('basvuruId');
        Route::post('/egitimler/{id}/basvurular/{basvuruId}/odeme', [DoctorEducationApiController::class, 'markApplicationPaid'])
            ->whereNumber('id')->whereNumber('basvuruId');
    });

    Route::middleware('doctor.paket:finans')->group(function () {
        Route::prefix('finans')->group(function () {
            Route::get('/ozet', [DoctorFinansApiController::class, 'ozet']);
            Route::get('/kategoriler', [DoctorFinansApiController::class, 'kategoriler']);
            Route::post('/kategoriler', [DoctorFinansApiController::class, 'storeKategori']);
            Route::put('/kategoriler/{id}', [DoctorFinansApiController::class, 'updateKategori']);
            Route::post('/kategoriler/{id}', [DoctorFinansApiController::class, 'updateKategori']);
            Route::post('/kategoriler/{id}/toggle', [DoctorFinansApiController::class, 'toggleKategori']);
            Route::delete('/kategoriler/{id}', [DoctorFinansApiController::class, 'destroyKategori']);
            Route::get('/gelirler', [DoctorFinansApiController::class, 'gelirler']);
            Route::post('/gelirler', [DoctorFinansApiController::class, 'storeGelir']);
            Route::put('/gelirler/{id}', [DoctorFinansApiController::class, 'updateGelir']);
            Route::post('/gelirler/{id}/guncelle', [DoctorFinansApiController::class, 'updateGelir']);
            Route::post('/gelirler/{id}/kalem', [DoctorFinansApiController::class, 'storeGelirKalem']);
            Route::delete('/gelirler/{odemeId}/kalem/{kalemId}', [DoctorFinansApiController::class, 'destroyGelirKalem']);
            Route::delete('/gelirler/{id}', [DoctorFinansApiController::class, 'destroyGelir']);
            Route::get('/giderler', [DoctorFinansApiController::class, 'giderler']);
            Route::post('/giderler', [DoctorFinansApiController::class, 'storeGider']);
            Route::put('/giderler/{id}', [DoctorFinansApiController::class, 'updateGider']);
            Route::post('/giderler/{id}/guncelle', [DoctorFinansApiController::class, 'updateGider']);
            Route::delete('/giderler/{id}', [DoctorFinansApiController::class, 'destroyGider']);
            Route::get('/hasta-bakiyeleri', [DoctorFinansApiController::class, 'hastaBakiyeleri']);
            Route::get('/hasta/{hastaId}', [DoctorFinansApiController::class, 'hastaHesap'])->whereNumber('hastaId');
            Route::post('/hasta/{hastaId}/tahsilat', [DoctorFinansApiController::class, 'hastaTahsilat'])->whereNumber('hastaId');
            Route::post('/hasta/{hastaId}/borc', [DoctorFinansApiController::class, 'hastaBorcEkle'])->whereNumber('hastaId');
            Route::get('/rapor', [DoctorFinansApiController::class, 'rapor']);
        });

        // Aliases for English/Mobile API paths
        Route::prefix('finance')->group(function () {
            Route::get('/overview', [DoctorFinansApiController::class, 'ozet']);
            Route::get('/report', [DoctorFinansApiController::class, 'rapor']);

            Route::get('/categories', [DoctorFinansApiController::class, 'kategoriler']);
            Route::post('/categories', [DoctorFinansApiController::class, 'storeKategori']);
            Route::put('/categories/{id}', [DoctorFinansApiController::class, 'updateKategori']);
            Route::post('/categories/{id}', [DoctorFinansApiController::class, 'updateKategori']);
            Route::post('/categories/{id}/toggle', [DoctorFinansApiController::class, 'toggleKategori']);
            Route::delete('/categories/{id}', [DoctorFinansApiController::class, 'destroyKategori']);

            Route::get('/incomes', [DoctorFinansApiController::class, 'gelirler']);
            Route::post('/incomes', [DoctorFinansApiController::class, 'storeGelir']);
            Route::post('/income', [DoctorFinansApiController::class, 'storeGelir']);
            Route::get('/incomes/{id}', [DoctorFinansApiController::class, 'showGelir'])->whereNumber('id');
            Route::put('/incomes/{id}', [DoctorFinansApiController::class, 'updateGelir'])->whereNumber('id');
            Route::post('/incomes/{id}/guncelle', [DoctorFinansApiController::class, 'updateGelir'])->whereNumber('id');
            Route::post('/incomes/{id}/items', [DoctorFinansApiController::class, 'storeGelirKalem'])->whereNumber('id');
            Route::delete('/incomes/{odemeId}/items/{kalemId}', [DoctorFinansApiController::class, 'destroyGelirKalem'])
                ->whereNumber('odemeId')->whereNumber('kalemId');
            Route::delete('/incomes/{id}', [DoctorFinansApiController::class, 'destroyGelir'])->whereNumber('id');

            Route::get('/expenses', [DoctorFinansApiController::class, 'giderler']);
            Route::post('/expenses', [DoctorFinansApiController::class, 'storeGider']);
            Route::put('/expenses/{id}', [DoctorFinansApiController::class, 'updateGider'])->whereNumber('id');
            Route::post('/expenses/{id}/guncelle', [DoctorFinansApiController::class, 'updateGider'])->whereNumber('id');
            Route::delete('/expenses/{id}', [DoctorFinansApiController::class, 'destroyGider'])->whereNumber('id');

            Route::get('/balances', [DoctorFinansApiController::class, 'hastaBakiyeleri']);
            Route::get('/patients/{hastaId}', [DoctorFinansApiController::class, 'hastaHesap'])->whereNumber('hastaId');
            Route::post('/patients/{hastaId}/collect', [DoctorFinansApiController::class, 'hastaTahsilat'])->whereNumber('hastaId');
            Route::post('/patients/{hastaId}/debt', [DoctorFinansApiController::class, 'hastaBorcEkle'])->whereNumber('hastaId');
        });
        Route::post('/finans/income', [DoctorFinansApiController::class, 'storeGelir']);
    });
};

// Doctor panel — web (bireysel site key + bearer)
Route::prefix('v1/doctor')
    ->middleware(['doctor.site.key', 'throttle:60,1'])
    ->group(function () use ($registerDoctorPanelRoutes) {
        Route::post('/auth/login', [DoctorAuthApiController::class, 'login'])->middleware('throttle:12,1');
        Route::post('/auth/two-factor', [DoctorAuthApiController::class, 'verifyTwoFactor'])->middleware('throttle:12,1');

        Route::middleware('doctor.api.token')->group(function () use ($registerDoctorPanelRoutes) {
            $registerDoctorPanelRoutes();

            // 2FA yönetimi
            Route::get('/two-factor', [DoctorAuthApiController::class, 'twoFactorStatus']);
            Route::post('/two-factor/setup', [DoctorAuthApiController::class, 'twoFactorBeginSetup']);
            Route::post('/two-factor/confirm', [DoctorAuthApiController::class, 'twoFactorConfirmSetup']);
            Route::post('/two-factor/disable', [DoctorAuthApiController::class, 'twoFactorDisable']);
            Route::post('/two-factor/recovery', [DoctorAuthApiController::class, 'twoFactorRegenerateRecovery']);

            // Online görüşme — hekim katılım bilgisi (platform SITE_URL WebRTC odası)
            Route::get('/randevular/{id}/gorusme', [DoctorPanelApiController::class, 'meetingSession'])->whereNumber('id');
        });
    });

// Doctor panel — mobile (bearer only, no site key required)
Route::prefix('mobile/v1/doctor')
    ->middleware(['throttle:60,1'])
    ->group(function () use ($registerDoctorPanelRoutes) {
        Route::post('/auth/login', [DoctorAuthApiController::class, 'login'])->middleware('throttle:12,1');
        Route::post('/auth/two-factor', [DoctorAuthApiController::class, 'verifyTwoFactor'])->middleware('throttle:12,1');

        Route::middleware('doctor.api.token')->group(function () use ($registerDoctorPanelRoutes) {
            $registerDoctorPanelRoutes();

            Route::get('/two-factor', [DoctorAuthApiController::class, 'twoFactorStatus']);
            Route::post('/two-factor/setup', [DoctorAuthApiController::class, 'twoFactorBeginSetup']);
            Route::post('/two-factor/confirm', [DoctorAuthApiController::class, 'twoFactorConfirmSetup']);
            Route::post('/two-factor/disable', [DoctorAuthApiController::class, 'twoFactorDisable']);
            Route::post('/two-factor/recovery', [DoctorAuthApiController::class, 'twoFactorRegenerateRecovery']);

            Route::get('/randevular/{id}/gorusme', [DoctorPanelApiController::class, 'meetingSession'])->whereNumber('id');
        });
    });

// Clinic doctor panel (klinik site key + bearer — kliniğe bağlı hekimler)
foreach (['v1/clinic/doctor', 'mobile/v1/clinic/doctor'] as $clinicDoctorPrefix) {
    Route::prefix($clinicDoctorPrefix)
        ->middleware(['clinic.site.key', 'throttle:60,1'])
        ->group(function () use ($registerDoctorPanelRoutes) {
            Route::post('/auth/login', [DoctorAuthApiController::class, 'clinicLogin'])->middleware('throttle:12,1');
            Route::post('/auth/two-factor', [DoctorAuthApiController::class, 'verifyTwoFactor'])->middleware('throttle:12,1');

            Route::middleware('doctor.api.token')->group(function () use ($registerDoctorPanelRoutes) {
                $registerDoctorPanelRoutes();

                Route::get('/two-factor', [DoctorAuthApiController::class, 'twoFactorStatus']);
                Route::post('/two-factor/setup', [DoctorAuthApiController::class, 'twoFactorBeginSetup']);
                Route::post('/two-factor/confirm', [DoctorAuthApiController::class, 'twoFactorConfirmSetup']);
                Route::post('/two-factor/disable', [DoctorAuthApiController::class, 'twoFactorDisable']);
                Route::post('/two-factor/recovery', [DoctorAuthApiController::class, 'twoFactorRegenerateRecovery']);
                Route::get('/randevular/{id}/gorusme', [DoctorPanelApiController::class, 'meetingSession'])->whereNumber('id');
            });
        });
}
