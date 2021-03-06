<?php

require_once __DIR__ . '/WikimediaCommandLine.inc';

$file = fopen( $args[0], 'r' );
if ( !$file ) {
	exit( 1 );
}

$wgUser = User::newSystemUser( 'Malayalam cleanup script', [ 'steal' => true ] );

$dbw = wfGetDB( DB_MASTER );

while ( !feof( $file ) ) {
	$line = fgets( $file );
	if ( $line === false ) {
		echo "Read error\n";
		exit( 1 );
	}
	$line = trim( $line );
	// Remove BOM
	$line = str_replace( "\xef\xbb\xbf", '', $line );

	if ( $line === '' ) {
		continue;
	}
	if ( !preg_match( '/^\[\[(.*)]]$/', $line, $m ) ) {
		echo "Invalid line: $line\n";
		print bin2hex( $line ) . "\n";
		continue;
	}
	$brokenTitle = Title::newFromText( $m[1] );
	if ( !preg_match( '/^Broken\//', $brokenTitle->getDBkey() ) ) {
		echo "Unbroken title: $line\n";
		continue;
	}

	$unbrokenTitle = Title::makeTitleSafe(
		$brokenTitle->getNamespace(),
		preg_replace( '/^Broken\//', '', $brokenTitle->getDBkey() ) );

	# Check that the broken title is a redirect
	$revision = Revision::newFromTitle( $brokenTitle );
	if ( !$revision ) {
		echo "Does not exist: $line\n";
		continue;
	}
	$content = $revision->getContent();
	$text = ContentHandler::getContentText( $content );
	if ( $text === false ) {
		echo "Cannot load text: $line\n";
		continue;
	}
	$redir = ContentHandler::makeContent( $text, null, CONTENT_MODEL_WIKITEXT )->getRedirectTarget();
	if ( !$redir ) {
		echo "Not a redirect: $line\n";
		continue;
	}

	if ( $unbrokenTitle->exists() ) {
		# Exists already, just delete this redirect
		$article = new Article( $brokenTitle );
		$success = $article->doDeleteArticle( 'Redundant redirect' );
		if ( $success ) {
			echo "Deleted: $line\n";
		} else {
			echo "Failed to delete: $line\n";
		}
	} else {
		# Does not exist, move this redirect to the unbroken title
		# Do not leave a redirect behind
		$result = $brokenTitle->moveTo( $unbrokenTitle, /*auth*/ false,
			'Fixing broken redirect', /*createRedirect*/ false );
		if ( $result === true ) {
			echo "Moved: $line\n";
		} else {
			$error = reset( $result );
			echo "Move error: {$error[0]}: $line\n";
		}
	}

	$dbw->commit( 'cleanupMI' );
	sleep( 1 );
	wfWaitForSlaves( 5 );
}
