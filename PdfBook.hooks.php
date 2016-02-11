<?php


/**
 * hooks for Extension PdfBook
 * see https://www.mediawiki.org/wiki/Extension:PdfBook
 */
class PdfBookHooks {

	/**
	 * Perform the export operation
	 */
	public static function onUnknownAction( $action, $article ) {
		global $wgOut, $wgUser, $wgParser, $wgRequest, $wgLogo;
		global $wgServer, $wgArticlePath, $wgScriptPath, $wgUploadPath, $wgUploadDirectory, $wgScript;

		if( $action == 'pdfbook' ) {

			$title = $article->getTitle();
			$opt = ParserOptions::newFromUser( $wgUser );

			// Log the export
			$msg = wfMessage( 'pdfbook-log', $wgUser->getUserPage()->getPrefixedText() )->text();
			$log = new LogPage( 'pdf', false );
			$log->addEntry( 'book', $article->getTitle(), $msg );

			// Initialise PDF variables
			$format  = $wgRequest->getText( 'format' );
			$notitle = $wgRequest->getText( 'notitle' );
			$titlepage=$wgRequest->getText( 'titlepage' );
			
			$layout  = $format == 'single' ? '--webpage' : '--firstpage toc';
			$charset = self::setProperty( 'Charset',     'iso-8859-1' );
			$left    = self::setProperty( 'LeftMargin',  '1cm' );
			$right   = self::setProperty( 'RightMargin', '1cm' );
			$top     = self::setProperty( 'TopMargin',   '1cm' );
			$bottom  = self::setProperty( 'BottomMargin','1cm' );
			$font    = self::setProperty( 'Font',	     'Arial' );
			$size    = self::setProperty( 'FontSize',    '8' );
			$ls      = self::setProperty( 'LineSpacing', 1 );
			$linkcol = self::setProperty( 'LinkColour',  '217A28' );
			$levels  = self::setProperty( 'TocLevels',   '2' );
			$exclude = self::setProperty( 'Exclude',     array() );
			$width   = self::setProperty( 'Width',       '' );
			$width   = $width ? "--browserwidth $width" : '';
			// new features 2016-01
			$header  = self::setProperty( 'Header',       '...' );
			$footer  = self::setProperty( 'Footer',       '.1.' );
			
			$logopath= self::setProperty( 'Logopath',  $_SERVER['DOCUMENT_ROOT'].$wgLogo);

			if( !is_array( $exclude ) ) $exclude = split( '\\s*,\\s*', $exclude );
 
			// Select articles from members if a category or links in content if not
			if( $format == 'single' ) {
				$articles = array( $title );
			} else {
				// get articles from a given category
				$articles = array();
				$pageIsCategory=$title->getNamespace() == NS_CATEGORY ;
				$linkList=$format == 'linklist';
				if( $pageIsCategory && ! $linkList) {
					$db     = wfGetDB( DB_SLAVE );
					$cat    = $db->addQuotes( $title->getDBkey() );
					$result = $db->select(
						'categorylinks',
						'cl_from',
						"cl_to = $cat",
						'PdfBook',
						array( 'ORDER BY' => 'cl_sortkey' )
					);
					if( $result instanceof ResultWrapper ) {
						$result = $result->result;
					}
					while ( $row = $db->fetchRow( $result ) ) {
						$articles[] = Title::newFromID( $row[0] );
					}
				} else {
					// get the current page
					$text = $article->fetchContent();
					$text = $wgParser->preprocess( $text, $title, $opt );
					// regulare expression to look for links in page
					$linkRegexp="/^\\*\\s*\\[{2}\\s*([^\\|\\]]+)\\s*.*?\\]{2}/m";
					// for each linkg found get the page title it points to
					if ( preg_match_all( $linkRegexp, $text, $links ) ) {
						foreach ( $links[1] as $link ) {
							$articles[] = Title::newFromText( $link );
						}
					}
				}
			}

			// Format the article(s) as a single HTML document with absolute URL's
			$book = $title->getText();
			$html = '';
			$wgArticlePath = $wgServer.$wgArticlePath;
			$wgPdfBookTab  = false;
			$wgScriptPath  = $wgServer.$wgScriptPath;
			$wgUploadPath  = $wgServer.$wgUploadPath;
			$wgScript      = $wgServer.$wgScript;
			if ($titlepage!="") {
				$html.=$titlepage;
			}
			foreach( $articles as $title ) {
				$ttext = $title->getPrefixedText();
				if( !in_array( $ttext, $exclude ) ) {
					$html.=self::getHtml($title,$ttext,$format,$opt,$notitle);
				}
			}

			// $wgPdfBookTab = false; If format=html in query-string, return html content directly
			if( $format == 'html' ) {
				$wgOut->disable();
				header( "Content-Type: text/html" );
				header( "Content-Disposition: attachment; filename=\"$book.html\"" );
				print $html;
			}
			else {
				// Write the HTML to a tmp file
				if( !is_dir( $wgUploadDirectory ) ) {
					mkdir( $wgUploadDirectory );
				}
				$file = "$wgUploadDirectory/" . uniqid( 'pdf-book' );
				file_put_contents( $file, $html );

				$toc    = $format == 'single' ? "" : " --toclevels $levels";

				// Send the file to the client via htmldoc converter
				$wgOut->disable();
				header( "Content-Type: application/pdf" );
				header( "Content-Disposition: attachment; filename=\"$book.pdf\"" );
				$cmd  = "--left $left --right $right --top $top --bottom $bottom";
                                $cmd .= " --header $header --footer $footer --headfootsize 8 --quiet --jpeg --color";
				$cmd .= " --bodyfont $font --fontsize $size --fontspacing $ls --linkstyle plain --linkcolor $linkcol";
				$cmd .= "$toc --no-title --format pdf14 --numbered $layout $width";
                                
			  $cmd .= " --logoimage $logopath";
				$cmd  = "htmldoc -t pdf --charset $charset $cmd $file";
				putenv( "HTMLDOC_NOCGI=1" );
				# debug the command
				file_put_contents("/tmp/hd","$cmd");
				# pipe the result of the command
				passthru( $cmd );
				// comment out unlink if you'd like to debug the intermediate result 
				@unlink( $file );
			}
			return false;
		}

		return true;
	}
	
	/**
	 * get the html code representation of the page with the given
	 * title
	 */
	private static function getHtml($title,$ttext,$format,$opt,$notitle) {
		global $wgParser;
		$article = new Article( $title );
		$text    = $article->fetchContent();
		$text    = preg_replace( "/<!--([^@]+?)-->/s", "@@" . "@@$1@@" . "@@", $text ); # preserve HTML comments
		if( $format != 'single' ) {
			$text .= "__NOTOC__";
		}
		$opt->setEditSection( false );    # remove section-edit links
		$out     = $wgParser->parse( $text, $title, $opt, true, true );
		$text    = $out->getText();
		$text    = preg_replace( "|(<img[^>]+?src=\")(/.+?>)|", "$1$wgServer$2", $text );      # make image urls absolute
		$text    = preg_replace( "|<div\s*class=['\"]?noprint[\"']?>.+?</div>|s", "", $text ); # non-printable areas
		$text    = preg_replace( "|@{4}([^@]+?)@{4}|s", "<!--$1-->", $text );                  # HTML comments hack
		$ttext   = basename( $ttext );
		$h1      = $notitle ? "" : "<center><h1>$ttext</h1></center>";
		$html    = utf8_decode( "$h1$text\n" );
		return $html;	
	}


	/**
	 * Return a property for htmldoc using global, request or passed default
	 */
	private static function setProperty( $name, $default ) {
		global $wgRequest;
		if ( $wgRequest->getText( "pdf$name" ) ) return $wgRequest->getText( "pdf$name" );
		if ( $wgRequest->getText( "amp;pdf$name" ) ) return $wgRequest->getText( "amp;pdf$name" ); // hack to handle ampersand entities in URL
		if ( isset( $GLOBALS["wgPdfBook$name"] ) ) return $GLOBALS["wgPdfBook$name"];
		return $default;
	}


	/**
	 * Add PDF to actions tabs in MonoBook based skins
	 */
	public static function onSkinTemplateTabs( $skin, &$actions) {
		global $wgPdfBookTab;

		if ( $wgPdfBookTab ) {
			$actions['pdfbook'] = array(
				'class' => false,
				'text' => $skin->msg( 'pdfbook-action' )->text(),
				'href' => $skin->getTitle()->getLocalURL( "action=pdfbook&format=single" ),
			);
		}
		return true;
	}


	/**
	 * Add PDF to actions tabs in vector based skins
	 */
	public static function onSkinTemplateNavigation( $skin, &$actions ) {
		global $wgPdfBookTab;

		if ( $wgPdfBookTab ) {
			$actions['views']['pdfbook'] = array(
				'class' => false,
				'text' => $skin->msg( 'pdfbook-action' )->text(),
				'href' => $skin->getTitle()->getLocalURL( "action=pdfbook&format=single" ),
			);
		}
		return true;
	}
}
