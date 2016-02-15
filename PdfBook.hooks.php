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
			
			$layout  = $format == 'single' ? '--webpage' : '--firstpage toc';
			$charset = self::setProperty( 'Charset',     'iso-8859-1' );
			$left    = self::setProperty( 'LeftMargin',  '1cm' );
			$right   = self::setProperty( 'RightMargin', '1cm' );
			$top     = self::setProperty( 'TopMargin',   '1cm' );
			$bottom  = self::setProperty( 'BottomMargin','1cm' );
			$font    = self::setProperty( 'Font',	     'Arial' );
			$size    = self::setProperty( 'FontSize',    '8' );
			$ls      = self::setProperty( 'LineSpacing', 1 );
			$linkcol = self::setProperty( 'LinkColour',  '0000EE' );
			$levels  = self::setProperty( 'TocLevels',   '2' );
			$exclude = self::setProperty( 'Exclude',     array() );
			$width   = self::setProperty( 'Width',       '' );
			$width   = $width ? "--browserwidth $width" : '';
			// new features 2016-01
			$header    = self::setProperty( 'Header',       '...' );
			$footer    = self::setProperty( 'Footer',       '.1.' );
			$debug     = self::setProperty( 'Debug',       false );
			$timeout   = self::setProperty( 'TimeOut',     30 );
			$titlepage = self::setProperty( 'TitlePage', '' );
						
			$logopath  = self::setProperty( 'Logopath',  $_SERVER['DOCUMENT_ROOT'].$wgLogo);
			// features for wkHhtmlToPdf
			$headerpage= self::setProperty( 'HeaderPage',   '' );
			$landscape = self::setProperty( 'Landscape',   false );
			$minimumfontsize=self::setProperty( 'MinimumFontSize',   10 );
			

			if( !is_array( $exclude ) ) {
				$exclude = split( '\\s*,\\s*', $exclude );
			}
 
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
					// uncomment to debug the list page
					file_put_contents( "/tmp/listpage.wiki", $text );
					// look for local page links in the [[ | form]]
					// these can be preceded with either * or <li>
					// this is the old regexpe that could only do *
					// $linkRegexp="/^\\*\\s*\\[{2}\\s*([^\\|\\]]+)\\s*.*?\\]{2}/m";
					$linkRegexp="/^((\\*)|(\\s*\\<li[^\\>]*\\>))\\s*\\[{2}\\s*[:]?([^\\|\\]]+)\\s*.*?\\]{2}/m";
					// for each link found get the page title it points to
					if ( preg_match_all( $linkRegexp, $text, $links ) ) {
						foreach ( $links[4] as $link ) {
							$articles[] = Title::newFromText( $link );
						}
					}
				}
			}

			// Format the article(s) as a single HTML document with absolute URL's
			$book = $title->getText();
			// start a proper HTML document
			$html = self::getHTMLHeader($title,$charset);
			$wgArticlePath = $wgServer.$wgArticlePath;
			$wgPdfBookTab  = true;
			$wgScriptPath  = $wgServer.$wgScriptPath;
			$wgUploadPath  = $wgServer.$wgUploadPath;
			$wgScript      = $wgServer.$wgScript;
			foreach( $articles as $title ) {
				$ttext = $title->getPrefixedText();
				if( !in_array( $ttext, $exclude ) ) {
					$html.=self::getHtml($title,$ttext,$format,$opt,$notitle);
					// force page break
					$html.="<div style='page-break-before:always'></div>";
				}
			}
			// end the HTML document
      $html .= self::getHTMLFooter();
			// $wgPdfBookTab = false; If format=html in query-string, return html content directly
			if( $format == 'html' ) {
				$wgOut->disable();
				header( "Content-Type: text/html" );
				header( "Content-Disposition: attachment; filename=\"$book.html\"" );
				print $html;
			} else {
				// Write the HTML to a tmp file
				if( !is_dir( $wgUploadDirectory ) ) {
					mkdir( $wgUploadDirectory );
				}
				$pdfid=uniqid( 'pdf-book' );
				$pdfdir=$wgUploadDirectory;
				$pdfdir="/tmp";
				$outputfile= "$pdfdir/" .$pdfid .".out";
				$file      = "$pdfdir/" .$pdfid .".html";
				$titlefile = "$pdfdir/" .$pdfid ."-title.html";
				$headerfile= "$pdfdir/" .$pdfid ."-header.html";
				$pdffile   = "$pdfdir/" .$pdfid .".pdf";
				file_put_contents( $file, $html );
				// get the HTML file for titlepage and headerpage (if any)
				self::getPageFile($titlepage ,$titlefile,$format,$opt,$charset);
				self::getPageFile($headerpage,$headerfile,$format,$opt,$charset);

				// check some default locations for htmldoc
				// add yours if this doesn't work
				$htmldoc="/usr/bin/htmldoc";
				$wkhtmltopdf="/usr/local/bin/wkhtmltopdf";
				$use_wkhtmltopdf=false;
				if (file_exists($wkhtmltopdf)) {
					$use_wkhtmltopdf=true;
					$pdfgen=$wkhtmltopdf;
				}
				/** if we use wkhtmltopdf the options from
				// http://wkhtmltopdf.org/usage/wkhtmltopdf.txt are relevant
				   * [page]       Replaced by the number of the pages currently being printed
				   * [frompage]   Replaced by the number of the first page to be printed
				   * [topage]     Replaced by the number of the last page to be printed
				   * [webpage]    Replaced by the URL of the page being printed
				   * [section]    Replaced by the name of the current section
				   * [subsection] Replaced by the name of the current subsection
				   * [date]       Replaced by the current date in system local format
				   * [isodate]    Replaced by the current date in ISO 8601 extended format
				   * [time]       Replaced by the current time in system local format
				   * [title]      Replaced by the title of the of the current page object
				   * [doctitle]   Replaced by the title of the output document
				   * [sitepage]   Replaced by the number of the page in the current site being converted
				   * [sitepages]  Replaced by the number of pages in the current site being converted
				*/
				if ($use_wkhtmltopdf) {
					$linesep=" \\\n"; // line separator
					$cmd=$wkhtmltopdf.$linesep;
					if ($landscape==true) {
						$cmd.=" -O landscape".$linesep;
					}
					$cmd.=" --encoding $charset ".$linesep;
					$cmd.=" --minimum-font-size ".$minimumfontsize.$linesep;
					$cmd.=" --margin-bottom 20mm".$linesep;
					if ($titlepage != "") {
						$cmd.= " cover $titlefile".$linesep;
					}
					// Table of content seting
					$toc    = $format == 'single' ? "" : " toc";
					$cmd.=" $toc ".$linesep;
					$cmd.=" page ".$file.$linesep;
					if ($headerpage != "") {
						$cmd.= " --header-spacing 10".$linesep;
						$cmd.= " --header-html $headerfile".$linesep;
					}
					$cmd.=" --footer-right '[page]/[topage]'".$linesep;
					$cmd.=" --footer-left '[date]'".$linesep;
					$cmd.=" --footer-spacing 10".$linesep;

					$cmd.=" ".$pdffile;
				} else {
					if (!file_exists($htmldoc)) {
						$htmldoc="/opt/local/bin/htmldoc";
					}
					if (!file_exists($htmldoc))  {
						die("PdfBook MediaWiki extension: htmldoc application path not configured. You might want to modify PdfBook.hooks.php.");
					}
					$pdfgen=$htmldoc;
					// $cmd  = "/opt/local/bin/htmldoc -t pdf --charset $charset $cmd $file";
					$cmd  = "--left $left --right $right --top $top --bottom $bottom";
					$cmd .= " --header $header --footer $footer --headfootsize 8 --quiet --jpeg --color";
					$cmd .= " --bodyfont $font --fontsize $size --fontspacing $ls --linkstyle plain --linkcolor $linkcol";
					// Table of content seting
					$toc    = $format == 'single' ? "" : " --toclevels $levels";

					$cmd .= "$toc --no-title --format pdf14 --numbered $layout $width";
                                
					$cmd .= " --logoimage $logopath";
					if ($titlepage != "") {
						$cmd.= " --titlefile $titlefile";
					}
					$cmd  = "$htmldoc -t pdf --charset $charset $cmd $file > 	$pdffile";
					putenv( "HTMLDOC_NOCGI=1" );
				}
				// uncomment if you'd like to force debugging
				$debug=true;
				$removeFiles=true;
				# optionally debug the command
				if ($debug) {
					file_put_contents("/tmp/hd","$cmd");
					$removeFiles=false;
				}
				# get the result of the command
				$error_code=0;
				// $htmldocoutput=array();
				// this is a debugging way to do things
				// exec($cmd,$htmldocoutput,$error_code );
				// file_put_contents($pdffile,implode($htmldocoutput));
				// run the command in background
				// see http://stackoverflow.com/questions/45953/php-execute-a-background-process#45966
				$pidArr=array();
				$error_code=0;
				exec(sprintf("%s > %s 2>&1 & echo $!", $cmd, $outputfile),$pidArr);
				$pid= $pidArr[0] ;
				$startTime = time();
				while(self::isRunning($pid)) {
					usleep(50000); // sleep 50 millisecs
   				if(time() > $startTime + $timeout) {
   					// terminate the process
   					posix_kill($pid,9);
   				  $error_code=1;
     				break;
     			}
   			};
				// $error_code=shell_exec($cmd);
				// display the result
				// in any case we do not show a wiki page but our own result
				$wgOut->disable();
				// check the success
				if ($error_code!=0) {
					// uncomment the following line if you'd like to keep the temporary files for debug inspection even if debug is off
					$removeFiles=false;
					// we should handle an error here
					echo self::getHTMLHeader("Error",$charset);
					echo "<div style='color:red'>PDF Creation Error</div>\n";
					echo "$cmd failed with error code $error_code\n";
					// echo implode("<br>\n",$htmldocoutput);
					echo "<h3>".$pdfgen." result</h3>\n";
					if (!file_exists($pdffile)) {
						echo "$pdffile does not exist";
					}
					echo self::getHTMLFooter();			
				} else {
					// scpcmd="scp /tmp/$pdfid* mars:/tmp";
					// shell_exec($scpcmd);
					// Send the resulting pdf file to the client
					header( "Content-Type: application/pdf" );
					header( "Content-Disposition: attachment; filename=\"$book.pdf\"" );
					readfile($pdffile);
				}
				if ($removeFiles) {
				  @unlink( $outfile) ;
				  @unlink( $file );
				  @unlink( $titlefile );	
				  @unlink( $headerfile);
				  @unlink( $pdffile);
				}	

			}
			return false;
		}

		return true;
	}
	
	/**
	 * check that the process is still running
	 */
	private static function isRunning($pid){
    try{
        $result = shell_exec(sprintf("ps %d", $pid));
        if( count(preg_split("/\n/", $result)) > 2){
            return true;
        }
    }catch(Exception $e){}

    return false;
	}
	
	/**
	 * wrap the given html into a html header and body including doctype
	 *
	 */
	private static function wrapInBody($html,$title,$encoding) {
		$result=self::getHtmlHeader($title,$encoding);
		$result.=$html;
		$result.=self::getHtmlFooter();
		return $result;
	}
	
	/**
	 * return a proper html header with Style sheet information
	 */
	private static function getHtmlHeader($title,$encoding) {
		$html="<!DOCTYPE html>\n".
          "<html lang='en' dir='ltr' class='client-nojs'>\n".
					"<head>\n".
					"<meta charset='".$encoding."' />\n".
					"<title>".$title."</title>\n".
					"<meta name='generator' content='PdfBook MediaWiki Extension' />\n".
					"<head>\n".
					"<body style='font-family: Arial; font-size:12px;margin:0; padding:0;'>\n";
    return $html;
	}
	
	/**
	 * return a proper html footer with Style sheet information
	 */
	private static function getHtmlFooter() {
		$html="</body>\n".
					"</html>\n";
    return $html;
	}
	
	/**
	 * get the page html for the given page title and put it in the given 
	 * file
	 */
	private static function getPageFile($pageTitle,$pagefile,$format,$opt,$encoding) {
	   // check if a titlepage was specified
 		 if ($pageTitle != "") {
			 	$l_ttext="";
		  	$l_notitle=true;
				$l_title=Title::newFromText( $pageTitle );
				$pagehtml=self::getHtml($l_title,$l_ttext,$format,$opt,$l_notitle);
				$pagehtml=self::wrapInBody($pagehtml,$l_title,$encoding);
				file_put_contents( $pagefile, $pagehtml );
		}
	}
	
	/**
	 * get the html code representation of the page with the given
	 * title
	 */
	private static function getHtml($title,$ttext,$format,$opt,$notitle) {
		global $wgParser,$wgServer;
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
