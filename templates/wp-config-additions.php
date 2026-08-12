<?php
/**
 * Smile Creative — standard wp-config defines.
 *
 * Paste above the "That's all, stop editing" line on every site.
 */

define( 'WP_MEMORY_LIMIT', '256M' );
define( 'DISALLOW_FILE_EDIT', true );   // no code editing from the dashboard
define( 'WP_DEBUG', false );
define( 'WP_DEBUG_DISPLAY', false );    // never leak a stack trace to a visitor
define( 'WP_DEBUG_LOG', false );
define( 'WP_POST_REVISIONS', 5 );       // stops postmeta bloating over years
define( 'FORCE_SSL_ADMIN', true );
define( 'EMPTY_TRASH_DAYS', 30 );
define( 'WP_AUTO_UPDATE_CORE', 'minor' );
define( 'FS_METHOD', 'direct' );        // no FTP prompt on plugin installs

/*
 * On a staging site, add:
 *   define( 'WP_ENVIRONMENT_TYPE', 'staging' );
 * and set Settings > Reading > discourage search engines.
 */
