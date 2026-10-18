// ignore: unused_import
import 'package:intl/intl.dart' as intl;

import 'app_localizations.dart';

// ignore_for_file: type=lint

/// The translations for Indonesian (`id`).
class AppLocalizationsId extends AppLocalizations {
  AppLocalizationsId([String locale = 'id']) : super(locale);

  @override
  String get appName => 'PeopleOS';

  @override
  String get languageTitle => 'Bahasa';

  @override
  String get languageEnglish => 'English';

  @override
  String get languageIndonesian => 'Bahasa Indonesia';

  @override
  String get languageSystem => 'Bawaan perangkat';

  @override
  String get languageSavedLocalOnly =>
      'Bahasa diubah di perangkat ini, tetapi belum bisa disimpan ke akun Anda.';

  @override
  String staleBanner(String age) {
    return 'Menampilkan data tersimpan dari $age. Hubungkan kembali lalu tarik untuk menyegarkan.';
  }

  @override
  String get ageJustNow => 'baru saja';

  @override
  String ageMinutes(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count menit lalu',
    );
    return '$_temp0';
  }

  @override
  String ageHours(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count jam lalu',
    );
    return '$_temp0';
  }

  @override
  String ageDays(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count hari lalu',
    );
    return '$_temp0';
  }

  @override
  String durationHm(int h, int m) {
    return '${h}j ${m}m';
  }

  @override
  String durationH(int h) {
    return '${h}j';
  }

  @override
  String durationM(int m) {
    return '${m}m';
  }

  @override
  String get greetingMorning => 'Selamat pagi';

  @override
  String get greetingAfternoon => 'Selamat siang';

  @override
  String get greetingEvening => 'Selamat sore';

  @override
  String get greetingNight => 'Selamat malam';

  @override
  String daysOne(String n) {
    return '$n hari';
  }

  @override
  String daysMany(String n) {
    return '$n hari';
  }

  @override
  String attendanceLate(String duration) {
    return 'Terlambat $duration';
  }

  @override
  String attendanceLateEstimate(String duration) {
    return 'Terlambat $duration (perkiraan)';
  }

  @override
  String get attendanceOnTime => 'Tepat waktu';

  @override
  String get attendanceHistoryTitle => 'Riwayat Absensi';

  @override
  String get attendanceRequestCorrection => 'Ajukan koreksi';

  @override
  String get attendancePreviousMonth => 'Bulan sebelumnya';

  @override
  String get attendanceNextMonth => 'Bulan berikutnya';

  @override
  String get attendanceSelectDay => 'Pilih tanggal';

  @override
  String get attendanceEmptyTitle => 'Belum ada absensi';

  @override
  String attendanceEmptyMessage(String month) {
    return 'Belum ada catatan untuk $month.';
  }

  @override
  String get attendanceStatPresent => 'Hadir';

  @override
  String get attendanceStatLate => 'Terlambat';

  @override
  String get attendanceStatHours => 'Jam';

  @override
  String attendanceStatSemantic(String label, String value) {
    return '$label: $value';
  }

  @override
  String attendanceRowSemanticLate(String date, String times, int minutes) {
    return '$date, $times, terlambat $minutes menit';
  }

  @override
  String attendanceRowSemanticOnTime(String date, String times) {
    return '$date, $times, tepat waktu';
  }

  @override
  String get attendanceBadgeWorking => 'Bekerja';

  @override
  String get attendanceBadgeCorrected => 'Dikoreksi';

  @override
  String get attendanceBadgeCorrection => 'Koreksi';

  @override
  String get attendanceBadgeInReview => 'Ditinjau';

  @override
  String get attendanceBadgeRejected => 'Ditolak';

  @override
  String get attendanceBadgePresent => 'Hadir';

  @override
  String get attendanceSavedOnDevice => 'Tersimpan di perangkat ini';

  @override
  String get attendanceCouldNotSync => 'Gagal disinkronkan';

  @override
  String get attendanceSyncFailed => 'Sinkronisasi gagal';

  @override
  String get attendancePendingSync => 'Menunggu sinkronisasi';

  @override
  String get attendanceCheckTypeIn => 'Check-in';

  @override
  String get attendanceCheckTypeOut => 'Check-out';

  @override
  String attendanceLeftEarly(String duration) {
    return 'Pulang $duration lebih awal';
  }

  @override
  String get attendanceKvCheckIn => 'Check-in';

  @override
  String get attendanceKvCheckOut => 'Check-out';

  @override
  String get attendanceKvWorked => 'Jam kerja';

  @override
  String get attendanceKvShift => 'Shift';

  @override
  String get attendanceKvLocation => 'Lokasi';

  @override
  String get attendanceCheckInDetails => 'Detail check-in';

  @override
  String get attendanceCheckOutDetails => 'Detail check-out';

  @override
  String get attendanceReviewNote => 'Catatan tinjauan';

  @override
  String get attendanceInsideGeofence => 'Dalam area kerja';

  @override
  String get attendanceOutsideGeofence => 'Luar area kerja';

  @override
  String get attendanceRecordedOffline => 'Direkam secara offline';

  @override
  String get attendanceSelfieUnavailable => 'Selfie tidak tersedia';

  @override
  String get attendanceSelfieSemantic => 'Selfie absensi';

  @override
  String get attendanceErrNoConnection => 'Tidak ada koneksi internet.';

  @override
  String get attendanceErrTimeout => 'Server terlalu lama merespons.';

  @override
  String get attendanceErrServer =>
      'Terjadi kesalahan pada server. Silakan coba lagi.';

  @override
  String get attendanceErrRateLimited =>
      'Terlalu banyak permintaan. Mohon tunggu sebentar.';

  @override
  String get attendanceErrSession =>
      'Sesi Anda berakhir. Masuk kembali untuk mengirim aksi ini.';

  @override
  String get authEnterEmail => 'Masukkan alamat email Anda';

  @override
  String get authEnterValidEmail => 'Masukkan alamat email yang valid';

  @override
  String get authEnterServer => 'Masukkan alamat server';

  @override
  String get authEnterValidServer => 'Masukkan alamat server yang valid';

  @override
  String get authBadCredentials => 'Email atau kata sandi salah.';

  @override
  String get authAccountDisabled =>
      'Akun Anda telah dinonaktifkan. Silakan hubungi HR.';

  @override
  String get authNoEmployeeProfile =>
      'Tidak ada profil karyawan yang terhubung dengan akun ini.';

  @override
  String get authSessionExpired =>
      'Sesi Anda telah berakhir. Silakan masuk kembali.';

  @override
  String authErrorRetryIn(String message, int seconds) {
    return '$message Coba lagi dalam $seconds dtk.';
  }

  @override
  String get authWelcomeBack => 'Selamat datang kembali';

  @override
  String get authSignInSubtitle => 'Masuk ke akun Anda';

  @override
  String get authServerAddress => 'Alamat server';

  @override
  String get authServerHelper =>
      'Alamat server PeopleOS Anda. Emulator Android: http://10.0.2.2:8000';

  @override
  String get authEmailAddress => 'Alamat email';

  @override
  String get authPassword => 'Kata sandi';

  @override
  String get authPasswordHint => 'Masukkan kata sandi Anda';

  @override
  String get authPasswordRequired => 'Kata sandi wajib diisi';

  @override
  String get authShowPassword => 'Tampilkan kata sandi';

  @override
  String get authHidePassword => 'Sembunyikan kata sandi';

  @override
  String get authSignIn => 'Masuk';

  @override
  String get authForgotPassword => 'Lupa kata sandi?';

  @override
  String get authDemoAccount => 'Akun Demo';

  @override
  String get authDemoNote =>
      'Mode demo: data contoh di perangkat ini, tanpa server.';

  @override
  String get authForgotTitle => 'Lupa kata sandi Anda?';

  @override
  String get authForgotBody =>
      'Reset kata sandi dilakukan oleh administrator HR Anda. Minta mereka mereset kata sandi Anda, lalu masuk dengan kata sandi yang baru.';

  @override
  String get authGotIt => 'Mengerti';

  @override
  String get authRememberMe => 'Ingat saya';

  @override
  String get authChange => 'Ubah';

  @override
  String get authTagline => 'Kelola SDM. Permudah kerja.';

  @override
  String get authSplashSemantics => 'PeopleOS. Kelola SDM. Permudah kerja.';

  @override
  String authVersion(String version) {
    return 'v$version';
  }

  @override
  String get profilePersonalInfo => 'Informasi Pribadi';

  @override
  String get profileEmployment => 'Kepegawaian';

  @override
  String get profileWorkInfo => 'Informasi Pekerjaan';

  @override
  String get profileDocuments => 'Dokumen';

  @override
  String get profileNotifications => 'Notifikasi';

  @override
  String get profileSettings => 'Pengaturan';

  @override
  String profileNewCount(int count) {
    return '$count baru';
  }

  @override
  String get profileLogout => 'Keluar';

  @override
  String get profileSignOutTitle => 'Keluar dari akun?';

  @override
  String get profileSignOutAction => 'Keluar';

  @override
  String profileSignOutPending(int count) {
    return 'Anda memiliki $count aksi absensi yang belum tersinkron. Data tetap aman di perangkat ini dan akan disinkronkan setelah Anda masuk kembali.';
  }

  @override
  String get profileSignOutMessage =>
      'Anda perlu masuk kembali untuk menggunakan PeopleOS.';

  @override
  String get profileStatusActive => 'Aktif';

  @override
  String get profileStatusPending => 'Menunggu';

  @override
  String get profileStatusTerminated => 'Diberhentikan';

  @override
  String get profileStatusInactive => 'Tidak aktif';

  @override
  String get profileFullName => 'Nama lengkap';

  @override
  String get profileGender => 'Jenis kelamin';

  @override
  String get profileDateOfBirth => 'Tanggal lahir';

  @override
  String get profilePhone => 'Telepon';

  @override
  String get profileEmail => 'Email';

  @override
  String get profileAddress => 'Alamat';

  @override
  String get profileEmployeeNumber => 'Nomor karyawan';

  @override
  String get profileDepartment => 'Departemen';

  @override
  String get profilePosition => 'Jabatan';

  @override
  String get profileManager => 'Atasan';

  @override
  String get profileJoinDate => 'Tanggal bergabung';

  @override
  String get profileEmploymentType => 'Jenis kepegawaian';

  @override
  String get profileStatus => 'Status';

  @override
  String get profileBranch => 'Cabang';

  @override
  String get profileWorkLocation => 'Lokasi kerja';

  @override
  String get profileShift => 'Shift';

  @override
  String get profileShiftHours => 'Jam shift';

  @override
  String get profileCompany => 'Perusahaan';

  @override
  String get profileTimezone => 'Zona waktu';

  @override
  String get profileGenderMale => 'Laki-laki';

  @override
  String get profileGenderFemale => 'Perempuan';

  @override
  String get profileTypePermanent => 'Tetap';

  @override
  String get profileTypeContract => 'Kontrak';

  @override
  String get profileTypeProbation => 'Percobaan';

  @override
  String get profileTypeInternship => 'Magang';

  @override
  String get profileTypeFreelance => 'Freelance';

  @override
  String get profileTypePartTime => 'Paruh waktu';

  @override
  String get settingsTitle => 'Pengaturan';

  @override
  String get settingsAppearance => 'Tampilan';

  @override
  String get settingsThemeLight => 'Terang';

  @override
  String get settingsThemeDark => 'Gelap';

  @override
  String get settingsThemeSystem => 'Sistem';

  @override
  String get settingsGeneral => 'Umum';

  @override
  String get settingsSyncStatus => 'Status sinkronisasi';

  @override
  String get settingsSyncSubtitle => 'Absensi tersimpan di perangkat ini';

  @override
  String get settingsServerAddress => 'Alamat server';

  @override
  String get settingsNotSet => 'Belum diatur';

  @override
  String get settingsAddress => 'Alamat';

  @override
  String get settingsServerHelper =>
      'Emulator Android: http://10.0.2.2:8000  ·  Simulator iOS: http://127.0.0.1:8000';

  @override
  String get settingsServerSaved => 'Alamat server disimpan';

  @override
  String get settingsDemoControls => 'Kontrol demo';

  @override
  String get settingsAbout => 'Tentang';

  @override
  String get settingsAboutApp => 'Aplikasi';

  @override
  String get settingsAboutVersion => 'Versi';

  @override
  String get settingsAboutMode => 'Mode';

  @override
  String get settingsAboutCompany => 'Perusahaan';

  @override
  String get settingsAboutTimezone => 'Zona waktu';

  @override
  String get settingsAppName => 'PeopleOS Employee';

  @override
  String get settingsModeServer => 'Terhubung ke server';

  @override
  String get settingsModeDemo => 'Demo (data contoh offline)';

  @override
  String get settingsScenario => 'Skenario';

  @override
  String get settingsDemoScenario => 'Skenario demo';

  @override
  String get settingsScenarioNormal => 'Hari kerja normal';

  @override
  String get settingsScenarioSelfie => 'Wajib selfie (kebijakan ketat)';

  @override
  String get settingsScenarioRestDay => 'Bukan hari kerja';

  @override
  String get settingsScenarioNoSchedule => 'Belum ada jadwal';

  @override
  String get settingsScenarioOnLeave => 'Cuti disetujui hari ini';

  @override
  String get settingsScenarioTerminated => 'Karyawan diberhentikan (diblokir)';

  @override
  String get settingsSimOutside => 'Simulasikan di luar area kerja';

  @override
  String get settingsSimOutsideSub => 'GPS palsu ~330 m dari kantor';

  @override
  String get settingsSimOffline => 'Simulasikan offline';

  @override
  String get settingsSimOfflineSub => 'Coba simpan absensi tanpa koneksi';

  @override
  String get settingsResetDemo => 'Reset data demo';

  @override
  String get settingsResetDemoSub => 'Menghapus absensi dan pengajuan hari ini';

  @override
  String get settingsResetDemoTitle => 'Reset data demo?';

  @override
  String get settingsResetDemoMessage =>
      'Absensi dan pengajuan yang Anda buat di demo akan dihapus.';

  @override
  String get settingsReset => 'Reset';

  @override
  String get settingsDemoDataReset => 'Data demo direset';

  @override
  String get settingsDemoModeNote =>
      'Mode demo berjalan sepenuhnya di perangkat ini dengan data contoh.';

  @override
  String appComingSoonTitle(String feature) {
    return '$feature segera hadir';
  }

  @override
  String get appComingSoonGeneric => 'Segera hadir';

  @override
  String get appComingSoonDocuments =>
      'Dokumen karyawan yang aman dan pengingat kedaluwarsa hadir di rilis mendatang.';

  @override
  String get appComingSoonMore =>
      'Layanan karyawan lainnya sedang direncanakan. Absensi, pengajuan, dan profil Anda sudah siap digunakan hari ini.';

  @override
  String get appComingSoonDefault => 'Fitur ini hadir di rilis mendatang.';

  @override
  String get appFeatureDocuments => 'Dokumen';

  @override
  String get appFeatureMore => 'Lainnya';

  @override
  String get authServerHint => 'https://hr.perusahaananda.com';

  @override
  String get authEmailHint => 'anda@perusahaan.com';

  @override
  String get settingsServerHint => 'https://hr.perusahaananda.com';

  @override
  String get checkTitleCheckIn => 'Check-in';

  @override
  String get checkTitleCheckOut => 'Check-out';

  @override
  String get checkConfirmLocation => 'Konfirmasi lokasi Anda';

  @override
  String get checkConfirmCheckOutTitle => 'Konfirmasi check-out';

  @override
  String get checkSelfiePreviewTitle => 'Pratinjau selfie';

  @override
  String get checkGettingLocation => 'Mencari lokasi Anda...';

  @override
  String get checkGps => 'GPS';

  @override
  String get checkWaiting => 'Menunggu';

  @override
  String get checkLocationCaptured => 'Lokasi didapatkan';

  @override
  String get checkGeofence => 'Area kerja';

  @override
  String get checkWithinArea => 'Dalam area';

  @override
  String get checkOutsideArea => 'Di luar area';

  @override
  String get checkCheckedByServer => 'Diperiksa server';

  @override
  String get checkSelfie => 'Selfie';

  @override
  String get checkDone => 'Selesai';

  @override
  String get checkOfflineNote =>
      'Anda sedang offline. Absensi ini akan disimpan di perangkat dan dikirim saat Anda kembali online.';

  @override
  String get checkBackToHome => 'Kembali ke Beranda';

  @override
  String get checkConfirmCheckIn => 'Konfirmasi Check-in';

  @override
  String get checkConfirmCheckOut => 'Konfirmasi Check-out';

  @override
  String get checkTakeSelfieToContinue => 'Ambil selfie untuk melanjutkan.';

  @override
  String get checkRejectedLocation => 'Lokasi Anda tidak dapat dicatat';

  @override
  String get checkRejectedAttendance => 'Absensi Anda tidak dapat dicatat';

  @override
  String get checkAreaInside => 'Anda berada di dalam area kerja';

  @override
  String get checkAreaOutside => 'Anda tampaknya berada di luar area kerja';

  @override
  String get checkNoSite => 'Belum ada lokasi kerja untuk hari ini.';

  @override
  String checkDistanceFromSite(String distance, String site) {
    return '$distance dari $site';
  }

  @override
  String checkDistanceFromSiteLimit(String distance, String site, int limit) {
    return '$distance dari $site (batas $limit m)';
  }

  @override
  String checkGpsAccuracy(int meters) {
    return 'Akurasi GPS ±$meters m';
  }

  @override
  String get checkEstimatedOnDevice => 'Diperkirakan di perangkat ini';

  @override
  String get checkServerDecides =>
      'Keputusan akhir ada di server. Anda tetap bisa mencoba.';

  @override
  String get checkSelfieRequired => 'Selfie wajib';

  @override
  String get checkSelfieRequiredMsg =>
      'Tempat kerja Anda meminta foto pada setiap absensi.';

  @override
  String get checkTake => 'Ambil';

  @override
  String get checkYourSelfie => 'Selfie Anda';

  @override
  String get checkSelfieReady => 'Selfie siap';

  @override
  String get checkSelfieReadyMsg =>
      'Foto ini hanya dikirim bersama absensi ini.';

  @override
  String get checkRetake => 'Ulangi';

  @override
  String get checkRemove => 'Hapus';

  @override
  String get checkSelfiePreviewSemantic => 'Pratinjau selfie';

  @override
  String get checkUsePhoto => 'Gunakan foto';

  @override
  String get checkPermDeniedTitle => 'Izin lokasi diperlukan';

  @override
  String get checkPermDeniedMsg =>
      'PeopleOS menggunakan lokasi Anda hanya saat Anda check-in atau check-out, untuk memastikan Anda berada di tempat kerja. Aplikasi tidak pernah melacak Anda di latar belakang.';

  @override
  String get checkPermBlockedTitle => 'Lokasi diblokir';

  @override
  String get checkPermBlockedMsg =>
      'Akses lokasi untuk PeopleOS dimatikan. Buka pengaturan aplikasi, izinkan Lokasi, lalu kembali ke sini.';

  @override
  String get checkOpenAppSettings => 'Buka pengaturan aplikasi';

  @override
  String get checkServiceOffTitle => 'Layanan lokasi nonaktif';

  @override
  String get checkServiceOffMsg =>
      'Aktifkan lokasi (GPS) di perangkat ini agar kami dapat memastikan Anda berada di lokasi kerja.';

  @override
  String get checkOpenLocationSettings => 'Buka pengaturan lokasi';

  @override
  String get checkUnavailableTitle => 'Lokasi Anda tidak dapat diperoleh';

  @override
  String get checkUnavailableMsg =>
      'Pindah ke area terbuka atau dekat jendela, lalu coba lagi.';

  @override
  String checkResultCheckedInAt(String time) {
    return 'Check-in pukul $time';
  }

  @override
  String checkResultCheckedOutAt(String time) {
    return 'Check-out pukul $time';
  }

  @override
  String get checkResultSavedOnDevice => 'Tersimpan di perangkat ini';

  @override
  String checkResultOfflineInBody(String time) {
    return 'Check-in pukul $time (menunggu sinkronisasi). Data akan dikirim otomatis begitu koneksi kembali. Tidak ada yang hilang.';
  }

  @override
  String checkResultOfflineOutBody(String time) {
    return 'Check-out pukul $time (menunggu sinkronisasi). Data akan dikirim otomatis begitu koneksi kembali. Tidak ada yang hilang.';
  }

  @override
  String get checkWorkSiteFallback => 'lokasi kerja';

  @override
  String get checkSummaryRecorded => 'Absensi Anda sudah tercatat.';

  @override
  String checkSummaryLate(String duration) {
    return 'Anda terlambat $duration hari ini. Semoga hari Anda menyenangkan!';
  }

  @override
  String get checkSummaryOnTime => 'Anda tepat waktu. Selamat bekerja!';

  @override
  String checkSummaryWorked(String duration) {
    return 'Anda bekerja selama $duration hari ini. Sampai jumpa besok!';
  }

  @override
  String get checkMapLocating => 'Mencari lokasi Anda';

  @override
  String get checkMapGeneric => 'Peta lokasi';

  @override
  String checkMapRelative(String site) {
    return 'Peta yang menunjukkan posisi Anda terhadap $site';
  }

  @override
  String get commonCancel => 'Batal';

  @override
  String get commonOk => 'OK';

  @override
  String get commonTryAgain => 'Coba lagi';

  @override
  String get commonBack => 'Kembali';

  @override
  String get commonDone => 'Selesai';

  @override
  String get commonSave => 'Simpan';

  @override
  String get commonSubmit => 'Kirim';

  @override
  String get commonClose => 'Tutup';

  @override
  String get commonRetry => 'Coba lagi';

  @override
  String get commonSoon => 'Segera';

  @override
  String get commonYes => 'Ya';

  @override
  String get commonNo => 'Tidak';

  @override
  String commonClear(String label) {
    return 'Hapus $label';
  }

  @override
  String get commonSelect => 'Pilih';

  @override
  String get commonSelectItem => 'Pilih satu item';

  @override
  String get commonLoading => 'Memuat';

  @override
  String commonProfilePhotoOf(String name) {
    return 'Foto profil $name';
  }

  @override
  String commonSyncStatus(String label) {
    return 'Status sinkronisasi: $label';
  }

  @override
  String get commonOfflineTitle => 'Anda sedang offline.';

  @override
  String get commonOfflineSaved =>
      'Absensi disimpan di perangkat ini dan disinkronkan secara otomatis.';

  @override
  String commonOfflineWaiting(int count) {
    return '$count tindakan menunggu sinkronisasi.';
  }

  @override
  String get navHome => 'Beranda';

  @override
  String get navAttendance => 'Absensi';

  @override
  String get navRequests => 'Pengajuan';

  @override
  String get navPayslip => 'Slip gaji';

  @override
  String get navProfile => 'Profil';

  @override
  String get badgeActive => 'Aktif';

  @override
  String get badgePending => 'Menunggu';

  @override
  String get badgeApproved => 'Disetujui';

  @override
  String get badgeRejected => 'Ditolak';

  @override
  String get badgeExpired => 'Kedaluwarsa';

  @override
  String get badgeLate => 'Terlambat';

  @override
  String get badgeOnTime => 'Tepat waktu';

  @override
  String get badgeWorking => 'BEKERJA';

  @override
  String get badgeOffline => 'Offline';

  @override
  String get errOfflineTitle => 'Anda sedang offline';

  @override
  String get errTimeoutTitle => 'Server lambat merespons';

  @override
  String get errForbiddenTitle => 'Akses ditolak';

  @override
  String get errNotFoundTitle => 'Tidak ditemukan';

  @override
  String get errServerTitle => 'Masalah server';

  @override
  String get errGenericTitle => 'Terjadi kesalahan';

  @override
  String get errPleaseTryAgain => 'Silakan coba lagi.';

  @override
  String get errNoConnection =>
      'Tidak ada koneksi internet. Periksa jaringan Anda lalu coba lagi.';

  @override
  String get errTimeout => 'Server terlalu lama merespons.';

  @override
  String get errServer => 'Terjadi kesalahan pada server. Silakan coba lagi.';

  @override
  String get errForbidden => 'Anda tidak memiliki izin untuk melakukan ini.';

  @override
  String get errNotFound => 'Data yang Anda cari tidak ditemukan.';

  @override
  String get errUnauthorized =>
      'Sesi Anda telah berakhir. Silakan masuk kembali.';

  @override
  String get errRateLimited =>
      'Terlalu banyak permintaan. Silakan coba lagi sebentar lagi.';

  @override
  String get errUnknown => 'Terjadi kesalahan tak terduga. Silakan coba lagi.';

  @override
  String errAttOutsideGeofenceDistance(int distance, int radius) {
    return 'Anda berada $distance m dari lokasi kerja. Anda harus berada dalam jarak $radius m untuk mencatat absensi.';
  }

  @override
  String get errAttOutsideGeofence =>
      'Anda berada di luar area kerja yang diizinkan.';

  @override
  String get errAttGpsRequired => 'Lokasi diperlukan untuk mencatat absensi.';

  @override
  String get errAttGpsAccuracyTooLow =>
      'Akurasi GPS terlalu rendah. Pindah ke area terbuka lalu coba lagi.';

  @override
  String get errAttMockLocation =>
      'Lokasi palsu terdeteksi. Matikan lalu coba lagi.';

  @override
  String get errAttLocationStale =>
      'Data lokasi sudah terlalu lama. Silakan coba lagi.';

  @override
  String get errAttSelfieRequired => 'Swafoto diperlukan untuk absensi ini.';

  @override
  String get errAttAlreadyCheckedIn => 'Anda sudah check-in.';

  @override
  String get errAttAlreadyCheckedOut => 'Anda sudah check-out untuk hari ini.';

  @override
  String get errAttNotCheckedIn => 'Anda perlu check-in sebelum check-out.';

  @override
  String get errAttCheckOutTooLate =>
      'Batas waktu check-out untuk shift ini sudah berakhir.';

  @override
  String get errAttOnLeave =>
      'Anda sedang cuti yang disetujui hari ini, jadi check-in tidak diperlukan.';

  @override
  String get errAttNoSchedule => 'Anda tidak memiliki jadwal untuk hari ini.';

  @override
  String get errAttNotWorkingDay => 'Hari ini bukan hari kerja Anda.';

  @override
  String get errAttOfflineEventTooOld =>
      'Tindakan ini tersimpan offline terlalu lama sehingga tidak dapat diterima. Silakan ajukan koreksi absensi.';

  @override
  String get errAttInvalidEventTime =>
      'Waktu yang tercatat di perangkat ini tidak diterima. Silakan ajukan koreksi absensi.';

  @override
  String get errAttEmployeeTerminated =>
      'Masa kerja Anda telah berakhir. Absensi dinonaktifkan.';

  @override
  String get errAttEmployeeNotActive => 'Profil karyawan Anda belum aktif.';

  @override
  String get errAttAccountDisabled =>
      'Akun Anda telah dinonaktifkan. Hubungi HRD.';

  @override
  String get errAttIdempotencyConflict =>
      'Tindakan ini bertentangan dengan tindakan sebelumnya. Silakan muat ulang.';

  @override
  String get errFileMissing =>
      'File tidak ditemukan di server. Silakan hubungi HRD.';

  @override
  String get errFileUnavailable => 'File ini sudah tidak tersedia.';

  @override
  String get errFileForbidden => 'Anda tidak diizinkan membuka file ini.';

  @override
  String get errFileOpenFailed => 'Tidak dapat membuka file di perangkat ini.';

  @override
  String commonSharedFile(String name) {
    return 'Membuka menu bagikan untuk $name';
  }

  @override
  String commonDownloadedFile(String name) {
    return '$name diunduh';
  }

  @override
  String get documentsTitle => 'Dokumen';

  @override
  String get documentsDetailTitle => 'Dokumen';

  @override
  String get documentsSelectPrompt => 'Pilih dokumen';

  @override
  String get documentsEmptyTitle => 'Tidak ada dokumen';

  @override
  String get documentsEmptyFiltered =>
      'Tidak ada yang cocok dengan filter ini.';

  @override
  String get documentsEmptyDefault =>
      'Dokumen yang dibagikan HR kepada Anda akan muncul di sini.';

  @override
  String get documentsFilterAll => 'Semua';

  @override
  String documentsFilterSemantics(String label) {
    return 'Filter: $label';
  }

  @override
  String get documentsStatusActive => 'Aktif';

  @override
  String get documentsStatusExpiring => 'Segera berakhir';

  @override
  String get documentsStatusExpired => 'Kedaluwarsa';

  @override
  String get documentsStatusArchived => 'Diarsipkan';

  @override
  String documentsTypeCompany(String type) {
    return '$type · Perusahaan';
  }

  @override
  String documentsExpiredAgo(int n) {
    String _temp0 = intl.Intl.pluralLogic(
      n,
      locale: localeName,
      other: 'Kedaluwarsa $n hari lalu',
    );
    return '$_temp0';
  }

  @override
  String get documentsExpiresToday => 'Berakhir hari ini';

  @override
  String get documentsExpiresTomorrow => 'Berakhir besok';

  @override
  String documentsExpiresIn(int n) {
    return 'Berakhir dalam $n hari';
  }

  @override
  String get documentsOwner => 'Pemilik';

  @override
  String get documentsOwnerCompany => 'Dokumen perusahaan';

  @override
  String get documentsOwnerYou => 'Anda';

  @override
  String get documentsExpiryDate => 'Tanggal kedaluwarsa';

  @override
  String get documentsNoExpiry => 'Tanpa kedaluwarsa';

  @override
  String get documentsFile => 'Berkas';

  @override
  String documentsFileValue(String name, String size) {
    return '$name · $size';
  }

  @override
  String get documentsUploaded => 'Diunggah';

  @override
  String get documentsDownloadOpen => 'Unduh / buka';

  @override
  String documentsFileSizeBytes(int n) {
    return '$n B';
  }

  @override
  String documentsFileSizeKb(String n) {
    return '$n KB';
  }

  @override
  String documentsFileSizeMb(String n) {
    return '$n MB';
  }

  @override
  String documentsSemantics(String title, String type, String status) {
    return '$title, $type, $status';
  }

  @override
  String documentsSemanticsExpiry(
    String title,
    String type,
    String status,
    String expiry,
  ) {
    return '$title, $type, $status, $expiry';
  }

  @override
  String get homeAttendance => 'Absensi';

  @override
  String get homeQuickLeave => 'Cuti';

  @override
  String get homeQuickPayslip => 'Slip gaji';

  @override
  String get homeQuickAttendance => 'Absensi';

  @override
  String get homeQuickRequests => 'Pengajuan';

  @override
  String get homeQuickDocuments => 'Dokumen';

  @override
  String get homeQuickMore => 'Lainnya';

  @override
  String get homeLoadingToday => 'Memuat data hari ini';

  @override
  String get homeSavedWaitingSync =>
      'Tersimpan di perangkat ini. Menunggu sinkronisasi.';

  @override
  String homeFailedActions(int count) {
    return '$count aksi absensi gagal disinkronkan. Periksa';
  }

  @override
  String get homeStaleSchedule =>
      'Menampilkan jadwal terakhir Anda saat offline.';

  @override
  String homeShiftStarts(String time) {
    return 'Shift dimulai $time';
  }

  @override
  String get homeNotCheckedIn => 'Belum check-in';

  @override
  String get homeReadyWhenYouAre => 'Siap kapan pun Anda siap.';

  @override
  String get homeCheckedInTitle => 'Anda Sudah Check-in';

  @override
  String get homeBadgeWorking => 'BEKERJA';

  @override
  String get homeCheckedOutTitle => 'Sudah check-out';

  @override
  String homeCheckedOutWorked(String inTime, String outTime, String duration) {
    return '$inTime - $outTime · $duration jam kerja';
  }

  @override
  String get homeWaitingReview => 'Menunggu tinjauan';

  @override
  String get homeWaitingReviewMsg =>
      'Absensi Anda hari ini perlu ditinjau atasan sebelum dinyatakan final.';

  @override
  String get homeCorrectionPending => 'Koreksi tertunda';

  @override
  String get homeCorrectionPendingMsg =>
      'Pengajuan koreksi Anda menunggu persetujuan.';

  @override
  String get homeBadgePending => 'Menunggu';

  @override
  String get homeRejectedTitle => 'Absensi ditolak';

  @override
  String get homeRejectedMsg =>
      'Koreksi terakhir Anda ditolak. Hubungi atasan Anda.';

  @override
  String get homeBadgeRejected => 'Ditolak';

  @override
  String get homeNotWorkingDay => 'Bukan hari kerja';

  @override
  String get homeDayOffMsg =>
      'Selamat menikmati hari libur Anda. Check-in tidak diperlukan hari ini.';

  @override
  String get homeBadgeDayOff => 'Libur';

  @override
  String get homeOnLeaveTitle => 'Cuti hari ini';

  @override
  String get homeOnLeaveMsg =>
      'Selamat menikmati waktu cuti Anda. Check-in tidak diperlukan selama cuti Anda disetujui.';

  @override
  String get homeBadgeOnLeave => 'Cuti';

  @override
  String get homeNoScheduleTitle => 'Tidak ada jadwal hari ini';

  @override
  String get homeNoScheduleMsg =>
      'Belum ada shift untuk Anda. Minta HR atau atasan Anda untuk menetapkannya.';

  @override
  String get homeBadgeNoShift => 'Tanpa shift';

  @override
  String get homeBlockedTitle => 'Absensi tidak tersedia';

  @override
  String get homeBlockedTerminatedMsg =>
      'Masa kerja Anda telah berakhir, sehingga check-in dinonaktifkan. Riwayat Anda tetap tersedia.';

  @override
  String get homeBlockedInactiveMsg =>
      'Profil karyawan Anda belum aktif. Hubungi HR.';

  @override
  String get homeBadgeTerminated => 'Berhenti';

  @override
  String get homeBadgeInactive => 'Tidak aktif';

  @override
  String homeSinceTime(String time) {
    return 'Sejak $time';
  }

  @override
  String homeTimeWorkedSemantic(String duration) {
    return 'Waktu bekerja $duration';
  }

  @override
  String get homeCheckIn => 'Check-in';

  @override
  String get homeCheckOut => 'Check-out';

  @override
  String get homeViewRequests => 'Lihat pengajuan';

  @override
  String homeDepartment(String name) {
    return 'Departemen $name';
  }

  @override
  String get homeNotifications => 'Notifikasi';

  @override
  String homeUnreadCount(int count) {
    return '$count belum dibaca';
  }

  @override
  String get homeRecentActivity => 'Aktivitas terbaru';

  @override
  String get homeViewAll => 'Lihat semua';

  @override
  String get homeActivityUnavailable => 'Aktivitas sedang tidak tersedia.';

  @override
  String get homeNoAttendanceThisMonth =>
      'Belum ada absensi tercatat bulan ini.';

  @override
  String get homeNoUpcomingLeave => 'Tidak ada cuti mendatang';

  @override
  String get homeNextLeave => 'Cuti berikutnya';

  @override
  String get homePlanTimeOff => 'Rencanakan cuti dari menu Pengajuan';

  @override
  String homePendingCount(int count) {
    return '$count menunggu';
  }

  @override
  String homeSummarySemantic(String title, String subtitle, int count) {
    return '$title. $subtitle. $count pengajuan menunggu.';
  }

  @override
  String get homeUpdates => 'Pembaruan';

  @override
  String get homePayslipAvailable => 'Slip gaji tersedia';

  @override
  String homeDocsNeedAttention(int count) {
    return '$count dokumen perlu perhatian';
  }

  @override
  String homeExpiringSoon(int count) {
    return '$count segera berakhir';
  }

  @override
  String homeExpired(int count) {
    return '$count kedaluwarsa';
  }

  @override
  String get homeTodaysShift => 'Shift Hari Ini';

  @override
  String get homeNotStarted => 'Belum dimulai';

  @override
  String get homeOnSchedule => 'Sesuai Jadwal';

  @override
  String get homeNoShiftAssigned => 'Belum ada shift';

  @override
  String get homeRestDay => 'Hari libur';

  @override
  String get leaveErrOverlap =>
      'Tanggal ini bertabrakan dengan pengajuan cuti lain yang sedang menunggu atau sudah disetujui.';

  @override
  String leaveErrInsufficientBalance(
    num available,
    String requestedDays,
    String availableDays,
  ) {
    String _temp0 = intl.Intl.pluralLogic(
      available,
      locale: localeName,
      other:
          'Saldo tidak cukup: Anda mengajukan $requestedDays, tetapi hanya tersedia $availableDays.',
    );
    return '$_temp0';
  }

  @override
  String get leaveErrInsufficientBalanceGeneric =>
      'Saldo cuti tidak cukup untuk tanggal ini.';

  @override
  String get leaveErrNoticeTooShort =>
      'Jenis cuti ini harus diajukan lebih awal. Pilih tanggal mulai yang lebih lambat.';

  @override
  String get leaveErrTooFarInPast =>
      'Tanggal mulai cuti ini terlalu jauh ke belakang untuk jenis cuti ini.';

  @override
  String get leaveErrNoWorkingDays =>
      'Tanggal yang dipilih tidak mencakup hari kerja (akhir pekan dan hari libur tidak dihitung).';

  @override
  String get leaveErrSpansYears =>
      'Pengajuan tidak boleh melewati tahun cuti berikutnya. Pisahkan menjadi dua pengajuan.';

  @override
  String get leaveErrTooLong =>
      'Pengajuan ini melebihi batas maksimum untuk jenis cuti ini. Persingkat tanggalnya atau pisahkan menjadi beberapa pengajuan.';

  @override
  String get leaveErrHalfDaySingleDate =>
      'Cuti setengah hari harus dimulai dan berakhir pada tanggal yang sama.';

  @override
  String get leaveErrHalfDayNotAllowed =>
      'Jenis cuti ini tidak dapat diambil setengah hari.';

  @override
  String get leaveErrInvalidDateRange =>
      'Tanggal selesai harus sama dengan atau setelah tanggal mulai.';

  @override
  String get leaveErrTypeUnavailable => 'Jenis cuti ini sudah tidak tersedia.';

  @override
  String get leaveErrPolicyMissing =>
      'Jenis cuti ini belum memiliki kebijakan untuk Anda. Hubungi HR.';

  @override
  String get leaveErrAlreadyStarted =>
      'Cuti ini sudah dimulai dan tidak dapat dibatalkan lagi.';

  @override
  String get leaveErrRequestNotPending =>
      'Pengajuan ini sudah diputuskan atau dibatalkan. Tarik ke bawah untuk memuat ulang.';

  @override
  String get leaveErrEmployeeTerminated =>
      'Masa kerja Anda telah berakhir, sehingga cuti baru tidak dapat diajukan.';

  @override
  String get leaveErrEmployeeNotActive =>
      'Profil karyawan Anda belum aktif. Hubungi HR.';

  @override
  String get leaveStatusDraft => 'Draf';

  @override
  String get leaveStatusPending => 'Menunggu';

  @override
  String get leaveStatusApproved => 'Disetujui';

  @override
  String get leaveStatusRejected => 'Ditolak';

  @override
  String get leaveStatusCancelled => 'Dibatalkan';

  @override
  String get leaveHalfDayAm => 'Pagi (AM)';

  @override
  String get leaveHalfDayPm => 'Siang (PM)';

  @override
  String get leaveHintUnlimited => 'Tidak dibatasi saldo tahunan';

  @override
  String leaveHintPerYear(String days) {
    return '$days per tahun';
  }

  @override
  String leaveHintNotice(int n) {
    String _temp0 = intl.Intl.pluralLogic(
      n,
      locale: localeName,
      other: 'Ajukan paling lambat $n hari sebelumnya',
    );
    return '$_temp0';
  }

  @override
  String leaveHintMaxPast(int n) {
    String _temp0 = intl.Intl.pluralLogic(
      n,
      locale: localeName,
      other: 'Dapat dimulai hingga $n hari ke belakang',
    );
    return '$_temp0';
  }

  @override
  String leaveHintMaxConsecutive(int n) {
    return 'Maks. $n hari kerja per pengajuan';
  }

  @override
  String get leaveHintHalfDay => 'Bisa setengah hari';

  @override
  String get leaveHintReasonRequired => 'Alasan wajib diisi';

  @override
  String get leaveDurationCountedOnSubmit => 'Dihitung saat diajukan';

  @override
  String leaveDurationWithHalf(String days, String half) {
    return '$days · $half';
  }

  @override
  String get leaveBalanceDaysUsedYear => 'hari terpakai tahun ini';

  @override
  String leaveBalanceOfAvailable(String total) {
    return 'dari $total tersedia';
  }

  @override
  String leaveBalancePending(String n) {
    return '$n menunggu';
  }

  @override
  String get leaveBalanceNoLimit => 'Tanpa batas tahunan';

  @override
  String leaveBalanceUsedPending(String used, String pending) {
    return '$used terpakai · $pending menunggu';
  }

  @override
  String leaveBalanceSemantics(
    String type,
    String headline,
    String caption,
    String footer,
  ) {
    return '$type: $headline $caption. $footer.';
  }

  @override
  String leaveCardSemantics(
    String type,
    String dates,
    String duration,
    String status,
  ) {
    return '$type, $dates, $duration, $status';
  }

  @override
  String leaveCardSubtitle(String type, String duration) {
    return '$type · $duration';
  }

  @override
  String get leavePreviewIntro =>
      'Pilih jenis dan tanggal untuk melihat berapa hari kerja yang terpakai.';

  @override
  String get leavePreviewCalculating => 'Menghitung...';

  @override
  String get leavePreviewSummary => 'Ringkasan';

  @override
  String leavePreviewDaysOfLeave(String days) {
    return 'Cuti $days';
  }

  @override
  String get leavePreviewNotCounted =>
      'Akhir pekan dan hari libur nasional tidak dihitung.';

  @override
  String leavePreviewMore(int n) {
    return '+$n lainnya';
  }

  @override
  String get leavePreviewUnlimited => 'Tidak dibatasi saldo tahunan.';

  @override
  String leavePreviewNotEnough(String available, String needed) {
    return 'Saldo tidak cukup: tersedia $available, pengajuan ini memerlukan $needed.';
  }

  @override
  String leavePreviewLeftAfter(String after, String available) {
    return 'Sisa $after setelah pengajuan ini (saat ini tersedia $available).';
  }

  @override
  String get leaveRequestButton => 'Ajukan cuti';

  @override
  String get leaveEmptyTitle => 'Belum ada pengajuan cuti';

  @override
  String get leaveEmptyMessage =>
      'Rencanakan cuti Anda: pilih jenis dan tanggal, lalu lihat saldo sebelum mengajukan.';

  @override
  String leaveEmptyFiltered(String status) {
    String _temp0 = intl.Intl.selectLogic(status, {
      'draft': 'Tidak ada draf',
      'pending': 'Tidak ada pengajuan yang menunggu',
      'approved': 'Tidak ada pengajuan yang disetujui',
      'rejected': 'Tidak ada pengajuan yang ditolak',
      'cancelled': 'Tidak ada pengajuan yang dibatalkan',
      'other': 'Tidak ada pengajuan',
    });
    return '$_temp0';
  }

  @override
  String get leaveFilterAll => 'Semua';

  @override
  String get leaveFilterPending => 'Menunggu';

  @override
  String get leaveFilterApproved => 'Disetujui';

  @override
  String get leaveFilterDrafts => 'Draf';

  @override
  String get leaveFilterRejected => 'Ditolak';

  @override
  String get leaveFilterCancelled => 'Dibatalkan';

  @override
  String leaveFilterSemantics(String label) {
    return 'Filter: $label';
  }

  @override
  String get leaveDetailTitle => 'Pengajuan cuti';

  @override
  String get leaveConfirmDiscardTitle => 'Buang draf ini?';

  @override
  String get leaveConfirmCancelTitle => 'Batalkan cuti ini?';

  @override
  String get leaveConfirmCancelApprovedMessage =>
      'Cuti Anda yang sudah disetujui akan dibatalkan dan jumlah harinya dikembalikan ke saldo Anda.';

  @override
  String get leaveConfirmWithdrawMessage =>
      'Pengajuan akan ditarik. Anda dapat mengajukan yang baru nanti.';

  @override
  String get leaveConfirmDiscard => 'Buang';

  @override
  String get leaveConfirmCancelLeave => 'Batalkan cuti';

  @override
  String get leaveKeepIt => 'Jangan batalkan';

  @override
  String get leaveDraftDiscarded => 'Draf dibuang';

  @override
  String get leaveCancelledSnack => 'Cuti dibatalkan';

  @override
  String get leaveUnpaid => 'Tidak dibayar';

  @override
  String get leaveDetailDates => 'Tanggal';

  @override
  String get leaveDetailDuration => 'Durasi';

  @override
  String get leaveDetailType => 'Jenis';

  @override
  String get leaveDetailWorkingDays => 'Hari kerja';

  @override
  String get leaveDetailReason => 'Alasan';

  @override
  String get leaveDetailRejectionReason => 'Alasan penolakan';

  @override
  String get leaveDetailStatus => 'Status';

  @override
  String get leaveSubmitRequest => 'Ajukan';

  @override
  String get leaveSubmittedSnack => 'Pengajuan cuti terkirim';

  @override
  String get leaveEditDraft => 'Edit draf';

  @override
  String get leaveDiscardDraft => 'Buang draf';

  @override
  String get leaveCancelApproved => 'Batalkan cuti yang disetujui';

  @override
  String get leaveCancelRequest => 'Batalkan pengajuan';

  @override
  String get leaveStepCreated => 'Dibuat';

  @override
  String get leaveStepSubmitted => 'Diajukan untuk persetujuan';

  @override
  String get leaveStepDraft => 'Draf, belum diajukan';

  @override
  String get leaveStepWaiting => 'Menunggu persetujuan atasan Anda';

  @override
  String get leaveStepApproved => 'Disetujui';

  @override
  String leaveStepApprovedBy(String name) {
    return 'Disetujui oleh $name';
  }

  @override
  String get leaveStepRejected => 'Ditolak';

  @override
  String leaveStepRejectedBy(String name) {
    return 'Ditolak oleh $name';
  }

  @override
  String get leaveStepCancelled => 'Dibatalkan';

  @override
  String get leaveFormTitle => 'Ajukan cuti';

  @override
  String get leaveFormEditTitle => 'Edit draf cuti';

  @override
  String get leaveFormType => 'Jenis cuti';

  @override
  String get leaveFormTypeLoading => 'Memuat jenis cuti...';

  @override
  String get leaveFormTypeHint => 'Pilih jenis';

  @override
  String get leaveTypeNotAvailable => 'Belum tersedia untuk Anda';

  @override
  String get leaveTypeUnpaidNoLimit => 'Tidak dibayar · tanpa batas tahunan';

  @override
  String leaveTypeAvailableOf(String available, String total) {
    return '$available tersedia dari $total';
  }

  @override
  String get leaveFormFirstDayHelp => 'Hari pertama cuti';

  @override
  String get leaveFormLastDayHelp => 'Hari terakhir cuti';

  @override
  String get leaveValChooseType => 'Pilih jenis cuti.';

  @override
  String get leaveValChooseFirstDay => 'Pilih hari pertama.';

  @override
  String get leaveValChooseLastDay => 'Pilih hari terakhir.';

  @override
  String leaveValReasonRequired(String type) {
    return 'Alasan wajib diisi untuk $type.';
  }

  @override
  String get leaveDraftSaved => 'Draf disimpan';

  @override
  String get leaveSaveDraft => 'Simpan draf';

  @override
  String get leaveSaveAsDraft => 'Simpan sebagai draf';

  @override
  String get leaveSubmit => 'Ajukan';

  @override
  String get leaveFormFrom => 'Dari';

  @override
  String get leaveFormTo => 'Sampai';

  @override
  String get leaveFormFirstDay => 'Hari pertama';

  @override
  String get leaveFormLastDay => 'Hari terakhir';

  @override
  String get leaveFormLength => 'Durasi';

  @override
  String get leaveFormFullDay => 'Sehari penuh';

  @override
  String get leaveFormMorning => 'Pagi';

  @override
  String get leaveFormAfternoon => 'Siang';

  @override
  String get leaveFormReasonRequired => 'Alasan (wajib)';

  @override
  String get leaveFormReasonOptional => 'Alasan (opsional)';

  @override
  String get leaveFormReasonHint => 'mis. Acara keluarga di Medan';

  @override
  String get notifTitle => 'Notifikasi';

  @override
  String get notifMarkAllRead => 'Tandai semua dibaca';

  @override
  String get notifMarkAllFailed =>
      'Tidak dapat menandai notifikasi sebagai dibaca. Tidak ada yang diubah.';

  @override
  String get notifEmptyTitle => 'Semua sudah terbaca';

  @override
  String get notifEmptyMessage =>
      'Pembaruan tentang cuti, koreksi, dan lembur Anda akan muncul di sini.';

  @override
  String notifSemantics(String title, String body, String age) {
    return '$title. $body. $age';
  }

  @override
  String notifSemanticsUnread(String title, String body, String age) {
    return 'Belum dibaca. $title. $body. $age';
  }

  @override
  String get notifTypeLeavePendingReview => 'Pengajuan cuti perlu ditinjau';

  @override
  String get notifTypeLeaveApproved => 'Cuti disetujui';

  @override
  String get notifTypeLeaveRejected => 'Cuti ditolak';

  @override
  String get notifTypeCorrectionApproved => 'Koreksi kehadiran disetujui';

  @override
  String get notifTypeCorrectionRejected => 'Koreksi kehadiran ditolak';

  @override
  String get notifTypeOvertimeApproved => 'Lembur disetujui';

  @override
  String get notifTypeOvertimeRejected => 'Lembur ditolak';

  @override
  String get notifTypePayslipAvailable => 'Slip gaji tersedia';

  @override
  String get notifTypeDocumentExpiring => 'Dokumen segera berakhir';

  @override
  String get notifTypeDocumentExpired => 'Dokumen kedaluwarsa';

  @override
  String get payslipTitle => 'Slip Gaji';

  @override
  String get payslipDetailTitle => 'Slip Gaji';

  @override
  String get payslipSelectPrompt => 'Pilih slip gaji';

  @override
  String get payslipYearAll => 'Semua';

  @override
  String get payslipYearAllSemantics => 'Semua tahun';

  @override
  String payslipYearSemantics(String year) {
    return 'Tahun $year';
  }

  @override
  String get payslipEmptyTitle => 'Belum ada slip gaji';

  @override
  String get payslipEmptyMessage =>
      'Slip gaji Anda akan muncul di sini setelah penggajian suatu periode dirilis.';

  @override
  String get payslipStatusPaid => 'Dibayar';

  @override
  String get payslipStatusReleased => 'Dirilis';

  @override
  String payslipCardSemanticsPaid(String period, String amount) {
    return '$period, gaji bersih $amount, dibayar';
  }

  @override
  String payslipCardSemanticsReleased(String period, String amount) {
    return '$period, gaji bersih $amount, dirilis';
  }

  @override
  String get payslipCaption => 'SLIP GAJI';

  @override
  String payslipPeriodRange(String start, String end) {
    return 'Periode $start - $end';
  }

  @override
  String payslipPeriodRangePaid(String start, String end, String payDate) {
    return 'Periode $start - $end · Dibayar $payDate';
  }

  @override
  String payslipInputsPresent(String present, String working) {
    return '$present dari $working hari hadir';
  }

  @override
  String payslipInputsOvertime(String duration) {
    return 'Lembur $duration';
  }

  @override
  String payslipInputsUnpaidLeave(String days) {
    return '$days hari cuti tanpa gaji';
  }

  @override
  String get payslipEarnings => 'Pendapatan';

  @override
  String get payslipDeductions => 'Potongan';

  @override
  String get payslipGrossPay => 'Gaji kotor';

  @override
  String get payslipTotalDeductions => 'Total potongan';

  @override
  String get payslipNoDeductions => 'Tidak ada potongan pada periode ini.';

  @override
  String get payslipNetPay => 'Gaji bersih';

  @override
  String payslipNetPaySemantics(String amount) {
    return 'Gaji bersih $amount';
  }

  @override
  String payslipAdjustment(String reason) {
    return 'Penyesuaian: $reason';
  }

  @override
  String get payslipDownloadPdf => 'Unduh PDF';

  @override
  String get requestsTitle => 'Pengajuan';

  @override
  String get requestsTabLeave => 'Cuti';

  @override
  String get requestsTabCorrections => 'Koreksi';

  @override
  String get requestsTabOvertime => 'Lembur';

  @override
  String get requestsSelectPrompt => 'Pilih pengajuan';

  @override
  String get requestsStatusPending => 'Menunggu';

  @override
  String get requestsStatusApproved => 'Disetujui';

  @override
  String get requestsStatusRejected => 'Ditolak';

  @override
  String get requestsStatusCancelled => 'Dibatalkan';

  @override
  String get requestsNewCorrection => 'Ajukan koreksi';

  @override
  String get requestsCorrectionsEmptyTitle => 'Belum ada pengajuan koreksi';

  @override
  String get requestsCorrectionsEmptyMessage =>
      'Lupa absen masuk atau pulang? Ajukan koreksi dan atasan Anda akan meninjaunya.';

  @override
  String requestsCorrectionIn(String time) {
    return 'Masuk $time';
  }

  @override
  String requestsCorrectionOut(String time) {
    return 'Pulang $time';
  }

  @override
  String get requestsNewOvertime => 'Ajukan lembur';

  @override
  String get requestsOvertimeEmptyTitle => 'Belum ada pengajuan lembur';

  @override
  String get requestsOvertimeEmptyMessage =>
      'Berencana bekerja lebih lama? Ajukan permintaan lembur terlebih dahulu.';

  @override
  String get requestsDetailWorkDate => 'Tanggal kerja';

  @override
  String get requestsDetailCheckIn => 'Masuk';

  @override
  String get requestsDetailCheckOut => 'Pulang';

  @override
  String get requestsDetailSubmitted => 'Diajukan';

  @override
  String get requestsDetailTime => 'Waktu';

  @override
  String get requestsDetailRequested => 'Durasi diajukan';

  @override
  String get requestsDetailApproved => 'Durasi disetujui';

  @override
  String get requestsCorrectionDetailTitle => 'Pengajuan koreksi';

  @override
  String get requestsOvertimeDetailTitle => 'Pengajuan lembur';

  @override
  String get requestsCorrectionCancelTitle => 'Batalkan pengajuan ini?';

  @override
  String get requestsOvertimeCancelTitle => 'Batalkan pengajuan lembur ini?';

  @override
  String get requestsCancelMessage =>
      'Tindakan ini tidak dapat diurungkan. Anda dapat mengajukan permintaan baru nanti.';

  @override
  String get requestsCancelRequest => 'Batalkan pengajuan';

  @override
  String get requestsKeepIt => 'Jangan batalkan';

  @override
  String get requestsCancelledSnack => 'Pengajuan dibatalkan';

  @override
  String get requestsReason => 'Alasan';

  @override
  String get requestsReviewerNote => 'Catatan peninjau';

  @override
  String get requestsErrCorrectionAlreadyPending =>
      'Sudah ada koreksi yang menunggu persetujuan untuk tanggal ini.';

  @override
  String get requestsErrCorrectionWindowExpired =>
      'Koreksi hanya dapat diajukan untuk 30 hari terakhir.';

  @override
  String get requestsErrInvalidWorkDate =>
      'Tanggal kerja tersebut tidak valid.';

  @override
  String get requestsErrCheckInRequired =>
      'Jam masuk wajib diisi untuk pengajuan ini.';

  @override
  String get requestsErrOvertimeTooLong =>
      'Lembur tersebut melebihi batas yang diizinkan.';

  @override
  String get requestsErrOvertimeOverlap =>
      'Pengajuan ini bertabrakan dengan pengajuan lembur lain.';

  @override
  String get requestsErrNotPending =>
      'Pengajuan ini sudah tidak menunggu persetujuan, sehingga tidak dapat diubah.';

  @override
  String get requestsErrInvalidEventTime =>
      'Waktu yang Anda masukkan tidak valid.';

  @override
  String get requestsCorrectionDateHelp => 'Tanggal kerja (30 hari terakhir)';

  @override
  String get requestsValChooseWorkDate => 'Pilih tanggal kerja.';

  @override
  String get requestsValTimes => 'Isi jam masuk, jam pulang, atau keduanya.';

  @override
  String get requestsValReasonWhatHappened =>
      'Jelaskan apa yang terjadi (minimal 5 karakter).';

  @override
  String get requestsCorrectionSubmitted => 'Pengajuan koreksi terkirim';

  @override
  String get requestsSubmitRequest => 'Kirim pengajuan';

  @override
  String get requestsCorrectionIntro =>
      'Lupa absen masuk atau pulang? Beri tahu kami jam yang seharusnya. Atasan Anda akan meninjau pengajuan ini sebelum catatan Anda diubah.';

  @override
  String get requestsChooseDate => 'Pilih tanggal';

  @override
  String get requestsCheckInTime => 'Jam masuk';

  @override
  String get requestsCheckOutTime => 'Jam pulang';

  @override
  String get requestsOptional => 'Opsional';

  @override
  String get requestsCorrectionReasonHint =>
      'mis. Saya lupa absen pulang setelah rapat';

  @override
  String get requestsOvertimeDateHelp => 'Tanggal lembur';

  @override
  String get requestsValChooseDate => 'Pilih tanggal.';

  @override
  String get requestsValChooseStart => 'Pilih jam mulai.';

  @override
  String get requestsValChooseEnd => 'Pilih jam selesai.';

  @override
  String get requestsValEndAfterStart => 'Jam selesai harus setelah jam mulai.';

  @override
  String get requestsValReasonWhy => 'Jelaskan alasannya (minimal 5 karakter).';

  @override
  String get requestsOvertimeSubmitted => 'Pengajuan lembur terkirim';

  @override
  String get requestsOvertimeIntro =>
      'Ajukan persetujuan sebelum Anda bekerja lembur. Lembur yang disetujui dicatat untuk atasan Anda dan HR.';

  @override
  String get requestsDate => 'Tanggal';

  @override
  String get requestsStartTime => 'Jam mulai';

  @override
  String get requestsEndTime => 'Jam selesai';

  @override
  String get requestsStart => 'Mulai';

  @override
  String get requestsEnd => 'Selesai';

  @override
  String get requestsOvertimeReasonHint => 'Apa yang akan Anda kerjakan?';

  @override
  String requestsTimeRange(String start, String end) {
    return '$start - $end';
  }

  @override
  String requestsOvertimeSubtitle(String start, String end, String duration) {
    return '$start - $end · $duration';
  }

  @override
  String get syncSyncing => 'Menyinkronkan...';

  @override
  String syncFailedCount(int count) {
    return '$count sinkronisasi gagal';
  }

  @override
  String syncPendingCount(int count) {
    return '$count menunggu sinkronisasi';
  }

  @override
  String get syncAllSynced => 'Semua tersinkron';

  @override
  String get syncTitle => 'Status sinkronisasi';

  @override
  String get syncNow => 'Sinkronkan sekarang';

  @override
  String get syncEverythingSynced => 'Semua sudah tersinkron';

  @override
  String get syncEverythingSyncedMsg =>
      'Absensi yang Anda catat saat offline muncul di sini sampai diterima server.';

  @override
  String get syncOnline => 'Online';

  @override
  String get syncOffline => 'Offline';

  @override
  String syncPendingFailedSummary(int pending, int failed) {
    return '$pending menunggu · $failed gagal';
  }

  @override
  String get syncStateSaved => 'Tersimpan';

  @override
  String get syncStateSynced => 'Tersinkron';

  @override
  String syncAttempts(int count) {
    return '$count percobaan';
  }

  @override
  String get syncDiscard => 'Buang';

  @override
  String get syncDiscardTitle => 'Buang aksi ini?';

  @override
  String get syncDiscardMessage =>
      'Aksi ini tidak akan dikirim ke server. Gunakan pengajuan koreksi jika absensi Anda perlu diperbaiki.';
}
