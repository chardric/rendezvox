<?php

declare(strict_types=1);

// -- Handler autoloads --
require __DIR__ . '/handlers/HealthHandler.php';
require __DIR__ . '/handlers/NowPlayingHandler.php';
require __DIR__ . '/handlers/NextTrackHandler.php';
require __DIR__ . '/handlers/TrackPlayedHandler.php';
require __DIR__ . '/handlers/TrackStartedHandler.php';
require __DIR__ . '/handlers/SongSearchHandler.php';
require __DIR__ . '/handlers/SubmitRequestHandler.php';
require __DIR__ . '/handlers/ListRequestsHandler.php';
require __DIR__ . '/handlers/ApproveRequestHandler.php';
require __DIR__ . '/handlers/RejectRequestHandler.php';
require __DIR__ . '/handlers/EmergencyToggleHandler.php';
require __DIR__ . '/handlers/SkipTrackHandler.php';
require __DIR__ . '/handlers/StreamControlHandler.php';
require __DIR__ . '/handlers/AuthLoginHandler.php';
require __DIR__ . '/handlers/AuthMeHandler.php';
require __DIR__ . '/handlers/DashboardStatsHandler.php';
require __DIR__ . '/handlers/SongListHandler.php';
require __DIR__ . '/handlers/SongDetailHandler.php';
require __DIR__ . '/handlers/SongCreateHandler.php';
require __DIR__ . '/handlers/SongUpdateHandler.php';
require __DIR__ . '/handlers/SongToggleHandler.php';
require __DIR__ . '/handlers/SongTrashHandler.php';
require __DIR__ . '/handlers/SongDeactivateMissingHandler.php';
require __DIR__ . '/handlers/SongPurgeHandler.php';
require __DIR__ . '/handlers/ArtistListHandler.php';
require __DIR__ . '/handlers/ArtistCreateHandler.php';
require __DIR__ . '/handlers/CategoryListHandler.php';
require __DIR__ . '/handlers/CategoryCreateHandler.php';
require __DIR__ . '/handlers/PlaylistListHandler.php';
require __DIR__ . '/handlers/PlaylistDetailHandler.php';
require __DIR__ . '/handlers/PlaylistCreateHandler.php';
require __DIR__ . '/handlers/PlaylistUpdateHandler.php';
require __DIR__ . '/handlers/PlaylistDeleteHandler.php';
require __DIR__ . '/handlers/PlaylistSongAddHandler.php';
require __DIR__ . '/handlers/PlaylistSongBulkAddHandler.php';
require __DIR__ . '/handlers/PlaylistSongFolderAddHandler.php';
require __DIR__ . '/handlers/PlaylistBatchImportHandler.php';
require __DIR__ . '/handlers/PlaylistSongRemoveHandler.php';
require __DIR__ . '/handlers/PlaylistReorderHandler.php';
require __DIR__ . '/handlers/PlaylistShuffleHandler.php';
require __DIR__ . '/handlers/ScheduleListHandler.php';
require __DIR__ . '/handlers/ScheduleCreateHandler.php';
require __DIR__ . '/handlers/ScheduleUpdateHandler.php';
require __DIR__ . '/handlers/ScheduleDeleteHandler.php';
require __DIR__ . '/handlers/ScheduleBulkHandler.php';
require __DIR__ . '/handlers/SettingsListHandler.php';
require __DIR__ . '/handlers/SettingsUpdateHandler.php';
require __DIR__ . '/handlers/StatsListenersHandler.php';
require __DIR__ . '/handlers/StatsPopularSongsHandler.php';
require __DIR__ . '/handlers/StatsPopularRequestsHandler.php';
require __DIR__ . '/handlers/StationConfigHandler.php';
require __DIR__ . '/handlers/StationIdListHandler.php';
require __DIR__ . '/handlers/StationIdUploadHandler.php';
require __DIR__ . '/handlers/StationIdDeleteHandler.php';
require __DIR__ . '/handlers/StationIdStreamHandler.php';
require __DIR__ . '/handlers/StationIdRenameHandler.php';
require __DIR__ . '/handlers/SongYearsHandler.php';
require __DIR__ . '/handlers/RandomSongsHandler.php';
require __DIR__ . '/handlers/MediaBrowseHandler.php';
require __DIR__ . '/handlers/MediaPendingCountHandler.php';
require __DIR__ . '/handlers/MediaMkdirHandler.php';
require __DIR__ . '/handlers/MediaRenameHandler.php';
require __DIR__ . '/handlers/MediaDeleteHandler.php';
require __DIR__ . '/handlers/MediaMoveHandler.php';
require __DIR__ . '/handlers/MediaImportHandler.php';
require __DIR__ . '/handlers/MediaFoldersHandler.php';
require __DIR__ . '/handlers/MediaCopyHandler.php';
require __DIR__ . '/handlers/FileManagerBrowseHandler.php';
require __DIR__ . '/handlers/FileManagerTreeHandler.php';
require __DIR__ . '/handlers/FileManagerRenameHandler.php';
require __DIR__ . '/handlers/FileManagerMoveHandler.php';
require __DIR__ . '/handlers/FileManagerDeleteHandler.php';
require __DIR__ . '/handlers/FileManagerDeleteCheckHandler.php';
require __DIR__ . '/handlers/SSEHandler.php';
require __DIR__ . '/handlers/GenreScanHandler.php';
require __DIR__ . '/handlers/LibrarySyncHandler.php';
require __DIR__ . '/handlers/ArtistDedupHandler.php';
require __DIR__ . '/handlers/NormalizeHandler.php';
require __DIR__ . '/handlers/ScheduleReloadHandler.php';
require __DIR__ . '/handlers/DuplicateScanHandler.php';
require __DIR__ . '/handlers/DuplicateResolveHandler.php';
require __DIR__ . '/handlers/ManifestHandler.php';
require __DIR__ . '/handlers/WeatherHandler.php';
require __DIR__ . '/handlers/UserListHandler.php';
require __DIR__ . '/handlers/UserCreateHandler.php';
require __DIR__ . '/handlers/UserUpdateHandler.php';
require __DIR__ . '/handlers/UserDeleteHandler.php';
require __DIR__ . '/handlers/PasswordChangeHandler.php';
require __DIR__ . '/handlers/AvatarUploadHandler.php';
require __DIR__ . '/handlers/AvatarServeHandler.php';
require __DIR__ . '/handlers/ProfileUpdateHandler.php';
require __DIR__ . '/handlers/GeoHandler.php';
require __DIR__ . '/handlers/TestEmailHandler.php';
require __DIR__ . '/handlers/ForgotPasswordHandler.php';
require __DIR__ . '/handlers/ResetPasswordHandler.php';
require __DIR__ . '/handlers/ActivateAccountHandler.php';
require __DIR__ . '/handlers/LibraryStatsHandler.php';
require __DIR__ . '/handlers/DiskSpaceHandler.php';
require __DIR__ . '/handlers/RenamePathsHandler.php';
require __DIR__ . '/handlers/LogoUploadHandler.php';
require __DIR__ . '/handlers/LogoServeHandler.php';
require __DIR__ . '/handlers/CoverArtHandler.php';
require __DIR__ . '/handlers/SetupHandler.php';
require __DIR__ . '/handlers/SystemInfoHandler.php';

// -- Route definitions --
Router::get('/health',       [HealthHandler::class,      'handle']);
Router::get('/config',         [StationConfigHandler::class, 'handle']);
Router::get('/manifest.json',  [ManifestHandler::class,      'handle']);
Router::get('/weather',        [WeatherHandler::class,       'handle']);
Router::get('/now-playing',      [NowPlayingHandler::class,  'handle']);
Router::get('/sse/now-playing', [SSEHandler::class,         'handle']);
Router::get('/next-track',   [NextTrackHandler::class,   'handle']);
Router::post('/track-played',  [TrackPlayedHandler::class,  'handle']);
Router::post('/track-started', [TrackStartedHandler::class, 'handle']);

// -- Song search (public) --
Router::get('/search-song',            [SongSearchHandler::class,     'handle']);

// -- Request system --
Router::post('/request',               [SubmitRequestHandler::class,  'handle']);
Router::get('/admin/requests',         [ListRequestsHandler::class,   'handle']);
Router::post('/admin/approve-request', [ApproveRequestHandler::class, 'handle']);
Router::post('/admin/reject-request',  [RejectRequestHandler::class,  'handle']);

// -- Emergency --
Router::post('/admin/toggle-emergency', [EmergencyToggleHandler::class, 'handle']);
Router::post('/admin/skip-track',       [SkipTrackHandler::class,       'handle']);
Router::post('/admin/stream-control',   [StreamControlHandler::class,   'handle']);

// -- First-time setup (public, no auth) --
Router::get('/setup/status',  [SetupHandler::class, 'status']);
Router::post('/setup',        [SetupHandler::class, 'handle']);

// -- Auth --
Router::post('/admin/login', [AuthLoginHandler::class, 'handle']);
Router::get('/admin/me',     [AuthMeHandler::class,    'handle']);

// -- Password reset & account activation (public, no auth) --
Router::post('/forgot-password',   [ForgotPasswordHandler::class,   'handle']);
Router::post('/reset-password',    [ResetPasswordHandler::class,    'handle']);
Router::post('/activate-account',  [ActivateAccountHandler::class,  'handle']);

// -- Dashboard --
Router::get('/admin/stats/dashboard', [DashboardStatsHandler::class, 'handle']);

// -- Songs --
Router::get('/admin/songs',              [SongListHandler::class,     'handle']);
Router::get('/admin/songs/years',        [SongYearsHandler::class,   'handle']);
Router::get('/admin/songs/random',       [RandomSongsHandler::class, 'handle']);
Router::post('/admin/songs/trash',              [SongTrashHandler::class,              'trash']);
Router::post('/admin/songs/restore',            [SongTrashHandler::class,              'restore']);
Router::post('/admin/songs/deactivate-missing', [SongDeactivateMissingHandler::class,  'handle']);
Router::delete('/admin/songs/purge',          [SongPurgeHandler::class,   'purge']);
Router::delete('/admin/songs/purge-all',      [SongPurgeHandler::class,   'purgeAll']);
Router::delete('/admin/songs/purge-inactive', [SongPurgeHandler::class,   'purgeInactive']);
Router::get('/admin/songs/:id',          [SongDetailHandler::class,  'handle']);
Router::post('/admin/songs',             [SongCreateHandler::class,  'handle']);
Router::put('/admin/songs/:id',          [SongUpdateHandler::class,  'handle']);
Router::patch('/admin/songs/:id/toggle', [SongToggleHandler::class,  'handle']);

// -- Artists --
Router::get('/admin/artists',          [ArtistListHandler::class,   'handle']);
Router::post('/admin/artists',         [ArtistCreateHandler::class, 'handle']);

// -- Categories --
Router::get('/admin/categories',       [CategoryListHandler::class,   'handle']);
Router::post('/admin/categories',      [CategoryCreateHandler::class, 'handle']);

// -- Playlists --
Router::get('/admin/playlists',                    [PlaylistListHandler::class,       'handle']);
Router::post('/admin/playlists',                   [PlaylistCreateHandler::class,     'handle']);
// Batch import routes MUST come before :id routes (exact match before param match)
Router::post('/admin/playlists/batch-import',      [PlaylistBatchImportHandler::class, 'start']);
Router::get('/admin/playlists/batch-import',       [PlaylistBatchImportHandler::class, 'status']);
Router::delete('/admin/playlists/batch-import',    [PlaylistBatchImportHandler::class, 'stop']);
Router::get('/admin/playlists/:id',                [PlaylistDetailHandler::class,     'handle']);
Router::put('/admin/playlists/:id',                [PlaylistUpdateHandler::class,     'handle']);
Router::delete('/admin/playlists/:id',             [PlaylistDeleteHandler::class,     'handle']);
Router::post('/admin/playlists/:id/songs',         [PlaylistSongAddHandler::class,    'handle']);
Router::post('/admin/playlists/:id/songs/bulk',    [PlaylistSongBulkAddHandler::class,    'handle']);
Router::post('/admin/playlists/:id/songs/folder',  [PlaylistSongFolderAddHandler::class,  'handle']);
Router::delete('/admin/playlists/:id/songs/:song_id', [PlaylistSongRemoveHandler::class, 'handle']);
Router::put('/admin/playlists/:id/reorder',        [PlaylistReorderHandler::class,    'handle']);
Router::post('/admin/playlists/:id/shuffle',       [PlaylistShuffleHandler::class,    'handle']);

// -- Schedules --
Router::get('/admin/schedules',         [ScheduleListHandler::class,   'handle']);
Router::post('/admin/schedules/bulk',   [ScheduleBulkHandler::class,   'handle']);
Router::post('/admin/schedules/reload', [ScheduleReloadHandler::class, 'handle']);
Router::post('/admin/schedules',        [ScheduleCreateHandler::class, 'handle']);
Router::put('/admin/schedules/:id',     [ScheduleUpdateHandler::class, 'handle']);
Router::delete('/admin/schedules/:id',  [ScheduleDeleteHandler::class, 'handle']);

// -- Station IDs --
Router::get('/admin/station-ids',              [StationIdListHandler::class,   'handle']);
Router::post('/admin/station-ids',             [StationIdUploadHandler::class, 'handle']);
Router::delete('/admin/station-ids/:filename', [StationIdDeleteHandler::class, 'handle']);
Router::put('/admin/station-ids/:filename/rename', [StationIdRenameHandler::class, 'handle']);
Router::get('/admin/station-ids/:filename/stream', [StationIdStreamHandler::class, 'handle']);

// -- Media file manager --
Router::get('/admin/media/pending-count', [MediaPendingCountHandler::class, 'handle']);
Router::get('/admin/media/browse',  [MediaBrowseHandler::class,  'handle']);
Router::get('/admin/media/folders', [MediaFoldersHandler::class, 'handle']);
Router::post('/admin/media/mkdir',  [MediaMkdirHandler::class,  'handle']);
Router::post('/admin/media/rename', [MediaRenameHandler::class, 'handle']);
Router::post('/admin/media/move',   [MediaMoveHandler::class,   'handle']);
Router::post('/admin/media/copy',   [MediaCopyHandler::class,   'handle']);

// -- File Manager (unfiltered filesystem browser + DB-aware operations) --
Router::get('/admin/files/browse',    [FileManagerBrowseHandler::class, 'handle']);
Router::get('/admin/files/tree',      [FileManagerTreeHandler::class,   'handle']);
Router::post('/admin/files/rename',   [FileManagerRenameHandler::class, 'handle']);
Router::post('/admin/files/move',     [FileManagerMoveHandler::class,   'handle']);
Router::delete('/admin/files/delete', [FileManagerDeleteHandler::class,      'handle']);
Router::get('/admin/files/delete-check', [FileManagerDeleteCheckHandler::class, 'handle']);
Router::post('/admin/media/import', [MediaImportHandler::class, 'handle']);
Router::delete('/admin/media/file', [MediaDeleteHandler::class, 'handle']);

// -- Users --
Router::get('/admin/users',            [UserListHandler::class,       'handle']);
Router::post('/admin/users',           [UserCreateHandler::class,     'handle']);
Router::put('/admin/users/:id',        [UserUpdateHandler::class,     'handle']);
Router::delete('/admin/users/:id',     [UserDeleteHandler::class,     'handle']);
Router::put('/admin/password',         [PasswordChangeHandler::class, 'handle']);
Router::put('/admin/profile',          [ProfileUpdateHandler::class,  'handle']);
Router::post('/admin/avatar',          [AvatarUploadHandler::class,   'handle']);
Router::get('/avatar/:id',             [AvatarServeHandler::class,    'handle']);
Router::post('/admin/logo',            [LogoUploadHandler::class,     'handle']);
Router::delete('/admin/logo',          [LogoUploadHandler::class,     'delete']);
Router::get('/logo',                   [LogoServeHandler::class,      'handle']);
Router::get('/cover',                  [CoverArtHandler::class,       'handle']);

// -- Settings --
Router::get('/admin/settings',         [SettingsListHandler::class,   'handle']);
Router::get('/admin/library-stats',    [LibraryStatsHandler::class,   'handle']);
Router::get('/admin/disk-space',       [DiskSpaceHandler::class,      'handle']);
Router::put('/admin/settings/:key',    [SettingsUpdateHandler::class, 'handle']);
Router::post('/admin/test-email',      [TestEmailHandler::class,      'handle']);
Router::get('/admin/system-info',      [SystemInfoHandler::class,     'handle']);

// -- Geo (PH location picker) --
Router::get('/admin/geo/provinces',    [GeoHandler::class, 'provinces']);
Router::get('/admin/geo/cities',       [GeoHandler::class, 'cities']);
Router::get('/admin/geo/barangays',    [GeoHandler::class, 'barangays']);
Router::get('/admin/geo/geocode',      [GeoHandler::class, 'geocode']);

// -- Genre scan --
Router::post('/admin/genre-scan',      [GenreScanHandler::class, 'start']);
Router::get('/admin/genre-scan',       [GenreScanHandler::class, 'status']);
Router::delete('/admin/genre-scan',    [GenreScanHandler::class, 'stop']);
Router::get('/admin/auto-tag-status',  [GenreScanHandler::class, 'autoTagStatus']);

// -- Library sync --
Router::post('/admin/library-sync',      [LibrarySyncHandler::class, 'start']);
Router::get('/admin/library-sync',       [LibrarySyncHandler::class, 'status']);
Router::delete('/admin/library-sync',    [LibrarySyncHandler::class, 'stop']);
Router::get('/admin/auto-sync-status',   [LibrarySyncHandler::class, 'autoSyncStatus']);

// -- Artist dedup --
Router::post('/admin/artist-dedup',      [ArtistDedupHandler::class, 'start']);
Router::get('/admin/artist-dedup',       [ArtistDedupHandler::class, 'status']);
Router::delete('/admin/artist-dedup',    [ArtistDedupHandler::class, 'stop']);
Router::get('/admin/auto-dedup-status',  [ArtistDedupHandler::class, 'autoDedupStatus']);

// -- Audio normalization --
Router::post('/admin/normalize',      [NormalizeHandler::class, 'start']);
Router::get('/admin/normalize',       [NormalizeHandler::class, 'status']);
Router::delete('/admin/normalize',    [NormalizeHandler::class, 'stop']);
Router::get('/admin/auto-norm-status', [NormalizeHandler::class, 'autoNormStatus']);

// -- Path rename (title-case) --
Router::post('/admin/rename-paths',        [RenamePathsHandler::class, 'start']);
Router::get('/admin/rename-paths',         [RenamePathsHandler::class, 'status']);
Router::delete('/admin/rename-paths',      [RenamePathsHandler::class, 'stop']);
Router::get('/admin/auto-rename-status',   [RenamePathsHandler::class, 'autoRenameStatus']);

// -- Duplicate detection --
Router::get('/admin/duplicates/scan',      [DuplicateScanHandler::class,    'handle']);
Router::post('/admin/duplicates/resolve',  [DuplicateResolveHandler::class, 'handle']);

// -- Analytics --
Router::get('/admin/stats/listeners',        [StatsListenersHandler::class,       'handle']);
Router::get('/admin/stats/popular-songs',    [StatsPopularSongsHandler::class,    'handle']);
Router::get('/admin/stats/popular-requests', [StatsPopularRequestsHandler::class, 'handle']);
