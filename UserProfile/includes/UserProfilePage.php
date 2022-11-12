<?php

use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;

/**
 * User profile Wiki Page
 *
 * @file
 * @ingroup Extensions
 * @author David Pean <david.pean@gmail.com>
 * @copyright Copyright © 2007, Wikia Inc.
 * @license GPL-2.0-or-later
 */

class UserProfilePage extends Article {

	/**
	 * @var Title
	 */
	public $title = null;

	/**
	 * @var User User object for the person whose profile is being viewed
	 */
	public $profileOwner;

	/**
	 * @var User User who is viewing someone's profile
	 */
	public $viewingUser;

	/**
	 * @var string user name of the user whose profile we're viewing
	 * @deprecated Prefer using getName() on $this->profileOwner or $this->viewingUser as appropriate
	 */
	public $user_name;

	/**
	 * @var int user ID of the user whose profile we're viewing
	 * @deprecated Prefer using getId() or better yet, getActorId(), on $this->profileOwner or $this->viewingUser as appropriate
	 */
	public $user_id;

	/**
	 * @var User User object representing the user whose profile we're viewing
	 * @deprecated Confusing name; prefer using $this->profileOwner or $this->viewingUser as appropriate
	 */
	public $user;

	/**
	 * @var bool is the current user the owner of the profile page?
	 */
	public $is_owner;

	/**
	 * @var array user profile data (interests, etc.) for the user whose
	 * profile we're viewing
	 */
	public $profile_data;

	/**
	 * @var array array of profile fields visible to the user viewing the profile
	 */
	public $profile_visible_fields;

	function __construct( $title ) {
		$context = $this->getContext();
		// This is the user *who is viewing* the page
		$user = $this->viewingUser = $context->getUser();

		parent::__construct( $title );
		// These vars represent info about the user *whose page is being viewed*
		$this->profileOwner = User::newFromName( $title->getText() );

		$this->user_name = $this->profileOwner->getName();
		$this->user_id = $this->profileOwner->getId();

		$this->user = $this->profileOwner;
		$this->user->load();

		$this->is_owner = ( $this->profileOwner->getName() == $user->getName() );

		$profile = new UserProfile( $this->profileOwner );
		$this->profile_data = $profile->getProfile();
		$this->profile_visible_fields = SPUserSecurity::getVisibleFields( $this->profileOwner, $this->viewingUser );
	}

	/**
	 * Is the current user the owner of the profile page?
	 * In other words, is the current user's username the same as that of the
	 * profile's owner's?
	 *
	 * @return bool
	 */
	function isOwner() {
		return $this->is_owner;
	}

	function view() {
		$context = $this->getContext();
		$out = $context->getOutput();
		$logger = LoggerFactory::getInstance( 'SocialProfile' );

		$out->setPageTitle( $this->getTitle()->getPrefixedText() );

		// No need to display noarticletext, we use our own message
		// @todo FIXME: this was basically "!$this->profileOwner" prior to actor.
		// Now we need to explicitly check for this b/c if we don't and we're viewing
		// the User: page of a nonexistent user as an anon, that profile page will
		// display as User:<your IP address> and $this->profileOwner will have been
		// set to a User object representing that anonymous user (IP address).
		if ( $this->profileOwner->isAnon() ) {
			parent::view();
			return '';
		}

		$out->addHTML( '<div id="profile-top">' );
		$out->addHTML( $this->getProfileHeader() );
		$out->addHTML( '<div class="visualClear"></div></div>' );

		// Add JS -- needed by UserBoard stuff but also by the "change profile type" button
		// If this were loaded in getUserBoard() as it originally was, then the JS that deals
		// with the "change profile type" button would *not* work when the user is using a
		// regular wikitext user page despite that the social profile header would still be
		// displayed.
		// @see T202272, T242689
		$out->addModules( 'ext.socialprofile.userprofile.js' );

		// User does not want social profile for User:user_name, so we just
		// show header + page content
		if (
			$this->getTitle()->getNamespace() == NS_USER &&
			$this->profile_data['actor'] &&
			$this->profile_data['user_page_type'] == 0
		) {
			parent::view();
			return '';
		}

		// Left side
		$out->addHTML( '<div id="user-page-left" class="clearfix">' );

		// Avoid PHP 7.1 warning of passing $this by reference
		$userProfilePage = $this;

		if ( !Hooks::run( 'UserProfileBeginLeft', [ &$userProfilePage ] ) ) {
			$logger->debug( "{method}: UserProfileBeginLeft messed up profile!\n", [
				'method' => __METHOD__
			] );
		}

		$out->addHTML( $this->getRelationships( 1 ) );
		$out->addHTML( $this->getRelationships( 2 ) );
		$out->addHTML( $this->getUserStats() );
		$out->addHTML( $this->getInterests() );

		if ( !Hooks::run( 'UserProfileEndLeft', [ &$userProfilePage ] ) ) {
			$logger->debug( "{method}: UserProfileEndLeft messed up profile!\n", [
				'method' => __METHOD__
			] );
		}

		$out->addHTML( '</div>' );

		$logger->debug( "profile start right\n" );

		// Right side
		$out->addHTML( '<div id="user-page-right" class="clearfix">' );

		if ( !Hooks::run( 'UserProfileBeginRight', [ &$userProfilePage ] ) ) {
			$logger->debug( "{method}: UserProfileBeginRight messed up profile!\n", [
				'method' => __METHOD__
			] );
		}

		$out->addHTML( $this->getPersonalInfo() );
		// @phan-suppress-next-line SecurityCheck-XSS
		$out->addHTML( $this->getActivity() );
		// Hook for BlogPage
		if ( !Hooks::run( 'UserProfileRightSideAfterActivity', [ $this ] ) ) {
			$logger->debug( "{method}: UserProfileRightSideAfterActivity hook messed up profile!\n", [
				'method' => __METHOD__
			] );
		}
		//$out->addHTML( $this->getCasualGames() );
		$out->addHTML( $this->getUserBoard( $context->getUser() ) );

		if ( !Hooks::run( 'UserProfileEndRight', [ &$userProfilePage ] ) ) {
			$logger->debug( "{method}: UserProfileEndRight messed up profile!\n", [
				'method' => __METHOD__
			] );
		}

		$out->addHTML( '</div><div class="visualClear"></div>' );
	}

	/**
	 * @param string $label Should be pre-escaped
	 * @param int $value
	 * @return string HTML
	 */
	function getUserStatsRow( $label, int $value ) {
		$output = ''; // Prevent E_NOTICE

		if ( $value != 0 ) {
			$context = $this->getContext();
			$language = $context->getLanguage();

			$formattedValue = htmlspecialchars( $language->formatNum( $value ), ENT_QUOTES );
			$output = "<div>
					<b>{$label}</b>
					{$formattedValue}
			</div>";
		}

		return $output;
	}

	function getUserStats() {
		global $wgUserProfileDisplay;

		if ( $wgUserProfileDisplay['stats'] == false ) {
			return '';
		}

		$output = ''; // Prevent E_NOTICE

		$stats = new UserStats( $this->profileOwner->getId(), $this->profileOwner->getName() );
		$stats_data = $stats->getUserStats();

		$total_value = $stats_data['edits'];

		if ( $total_value != 0 ) {
			$output .= '<div class="user-section-heading">
				<div class="user-section-title">' .
					wfMessage( 'statistics' )->escaped() .
				'</div>
				<div class="user-section-actions">
					<div class="action-right">
					</div>
					<div class="action-left">
					</div>
					<div class="visualClear"></div>
				</div>
			</div>
			<div class="visualClear"></div>
			<div class="profile-info-container bold-fix">' .
				$this->getUserStatsRow(
					wfMessage( 'user-stats-edits', $stats_data['edits'] )->escaped(),
					$stats_data['edits']
				);
			$output .= '</div>';
		}

		return $output;
	}

	static function sortItems( $x, $y ) {
		if ( $x['timestamp'] == $y['timestamp'] ) {
			return 0;
		} elseif ( $x['timestamp'] > $y['timestamp'] ) {
			return -1;
		} else {
			return 1;
		}
	}

	function getProfileSection( $label, $value, $required = true ) {
		$context = $this->getContext();
		$out = $context->getOutput();
		$user = $context->getUser();

		$output = '';
		if ( $value || $required ) {
			if ( !$value ) {
				if ( $user->getName() == $this->getTitle()->getText() ) {
					$value = wfMessage( 'profile-updated-personal' )->escaped();
				} else {
					$value = wfMessage( 'profile-not-provided' )->escaped();
				}
			}

			$value = $out->parseAsInterface( trim( $value ), false );

			$output = "<div><b>{$label}</b>{$value}</div>";
		}
		return $output;
	}

	function getPersonalInfo() {
		global $wgUserProfileDisplay;

		if ( $wgUserProfileDisplay['personal'] == false ) {
			return '';
		}

		$this->initializeProfileData();
		$profile_data = $this->profile_data;

		$defaultCountry = wfMessage( 'user-profile-default-country' )->inContentLanguage()->text();

		// Current location
		$location = $profile_data['location_city'] . ', ' . $profile_data['location_state'];
		if ( $profile_data['location_country'] != $defaultCountry ) {
			if ( $profile_data['location_city'] && $profile_data['location_state'] ) { // city AND state
				$location = $profile_data['location_city'] . ', ' .
							$profile_data['location_state'] . ', ' .
							$profile_data['location_country'];
				// Privacy
				$location = '';
				if ( in_array( 'up_location_city', $this->profile_visible_fields ) ) {
					$location .= $profile_data['location_city'] . ', ';
				}
				$location .= $profile_data['location_state'];
				if ( in_array( 'up_location_country', $this->profile_visible_fields ) ) {
					$location .= ', ' . $profile_data['location_country'] . ', ';
				}
			} elseif ( $profile_data['location_city'] && !$profile_data['location_state'] ) { // city, but no state
				$location = '';
				if ( in_array( 'up_location_city', $this->profile_visible_fields ) ) {
					$location .= $profile_data['location_city'] . ', ';
				}
				if ( in_array( 'up_location_country', $this->profile_visible_fields ) ) {
					$location .= $profile_data['location_country'];
				}
			} elseif ( $profile_data['location_state'] && !$profile_data['location_city'] ) { // state, but no city
				$location = $profile_data['location_state'];
				if ( in_array( 'up_location_country', $this->profile_visible_fields ) ) {
					$location .= ', ' . $profile_data['location_country'];
				}
			} else {
				$location = '';
				if ( in_array( 'up_location_country', $this->profile_visible_fields ) ) {
					$location .= $profile_data['location_country'];
				}
			}
		}

		if ( $location == ', ' ) {
			$location = '';
		}

		// Hometown
		/*$hometown = $profile_data['hometown_city'] . ', ' . $profile_data['hometown_state'];
		if ( $profile_data['hometown_country'] != $defaultCountry ) {
			if ( $profile_data['hometown_city'] && $profile_data['hometown_state'] ) { // city AND state
				$hometown = $profile_data['hometown_city'] . ', ' .
							$profile_data['hometown_state'] . ', ' .
							$profile_data['hometown_country'];
				$hometown = '';
				if ( in_array( 'up_hometown_city', $this->profile_visible_fields ) ) {
					$hometown .= $profile_data['hometown_city'] . ', ' . $profile_data['hometown_state'];
				}
				if ( in_array( 'up_hometown_country', $this->profile_visible_fields ) ) {
					$hometown .= ', ' . $profile_data['hometown_country'];
				}
			} elseif ( $profile_data['hometown_city'] && !$profile_data['hometown_state'] ) { // city, but no state
				$hometown = '';
				if ( in_array( 'up_hometown_city', $this->profile_visible_fields ) ) {
					$hometown .= $profile_data['hometown_city'] . ', ';
				}
				if ( in_array( 'up_hometown_country', $this->profile_visible_fields ) ) {
					$hometown .= $profile_data['hometown_country'];
				}
			} elseif ( $profile_data['hometown_state'] && !$profile_data['hometown_city'] ) { // state, but no city
				$hometown = $profile_data['hometown_state'];
				if ( in_array( 'up_hometown_country', $this->profile_visible_fields ) ) {
					$hometown .= ', ' . $profile_data['hometown_country'];
				}
			} else {
				$hometown = '';
				if ( in_array( 'up_hometown_country', $this->profile_visible_fields ) ) {
					$hometown .= $profile_data['hometown_country'];
				}
			}
		}

		if ( $hometown == ', ' ) {
			$hometown = '';
		}*/

		$joined_data = $profile_data['websites'] . $profile_data['about'];
		$edit_info_link = SpecialPage::getTitleFor( 'UpdateProfile' );

		// Privacy fields holy shit!
		$personal_output = '';
		/*if ( in_array( 'up_real_name', $this->profile_visible_fields ) ) {
			$personal_output .= $this->getProfileSection( wfMessage( 'user-personal-info-real-name' )->escaped(), $profile_data['real_name'], false );
		}*/

		$personal_output .= $this->getProfileSection( wfMessage( 'user-personal-info-location' )->escaped(), $location, false );
		//$personal_output .= $this->getProfileSection( wfMessage( 'user-personal-info-hometown' )->escaped(), $hometown, false );

		/*if ( in_array( 'up_birthday', $this->profile_visible_fields ) ) {
			$personal_output .= $this->getProfileSection( wfMessage( 'user-personal-info-birthday' )->escaped(), $profile_data['birthday'], false );
		}*/

		/*if ( in_array( 'up_occupation', $this->profile_visible_fields ) ) {
			$personal_output .= $this->getProfileSection( wfMessage( 'user-personal-info-occupation' )->escaped(), $profile_data['occupation'], false );
		}*/

		if ( in_array( 'up_websites', $this->profile_visible_fields ) ) {
			$personal_output .= $this->getProfileSection( wfMessage( 'user-personal-info-websites' )->escaped(), $profile_data['websites'], false );
		}

		/*if ( in_array( 'up_places_lived', $this->profile_visible_fields ) ) {
			$personal_output .= $this->getProfileSection( wfMessage( 'user-personal-info-places-lived' )->escaped(), $profile_data['places_lived'], false );
		}*/

		/*if ( in_array( 'up_schools', $this->profile_visible_fields ) ) {
			$personal_output .= $this->getProfileSection( wfMessage( 'user-personal-info-schools' )->escaped(), $profile_data['schools'], false );
		}*/

		if ( in_array( 'up_about', $this->profile_visible_fields ) ) {
			$personal_output .= $this->getProfileSection( wfMessage( 'user-personal-info-about-me' )->escaped(), $profile_data['about'], false );
		}

		$output = '';
		if ( $joined_data ) {
			$output .= '<div class="user-section-heading">
				<div class="user-section-title">' .
					wfMessage( 'user-personal-info-title' )->escaped() .
				'</div>
				<div class="user-section-actions">
					<div class="action-right">';
			if ( $this->viewingUser->getName() == $this->profileOwner->getName() ) {
				$output .= '<a href="' . htmlspecialchars( $edit_info_link->getFullURL() ) . '">' .
					wfMessage( 'user-edit-this' )->escaped() . '</a>';
			}
			$output .= '</div>
					<div class="visualClear"></div>
				</div>
			</div>
			<div class="visualClear"></div>
			<div class="profile-info-container">' .
				$personal_output .
			'</div>';
		} elseif ( $this->viewingUser->getName() == $this->profileOwner->getName() ) {
			$output .= '<div class="user-section-heading">
				<div class="user-section-title">' .
					wfMessage( 'user-personal-info-title' )->escaped() .
				'</div>
				<div class="user-section-actions">
					<div class="action-right">
						<a href="' . htmlspecialchars( $edit_info_link->getFullURL() ) . '">' .
							wfMessage( 'user-edit-this' )->escaped() .
						'</a>
					</div>
					<div class="visualClear"></div>
				</div>
			</div>
			<div class="visualClear"></div>
			<div class="no-info-container">' .
				wfMessage( 'user-no-personal-info' )->escaped() .
			'</div>';
		}

		return $output;
	}

	/**
	 * Get the custom info (site-specific stuff) for a given user.
	 *
	 * @return string HTML
	 */
	/*function getCustomInfo() {
		global $wgUserProfileDisplay;

		if ( $wgUserProfileDisplay['custom'] == false ) {
			return '';
		}

		$this->initializeProfileData();

		$profile_data = $this->profile_data;

		$joined_data = $profile_data['custom_1'] . $profile_data['custom_2'] .
						$profile_data['custom_3'] . $profile_data['custom_4'];
		$edit_info_link = SpecialPage::getTitleFor( 'UpdateProfile' );

		$custom_output = '';
		if ( in_array( 'up_custom_1', $this->profile_visible_fields ) ) {
			$custom_output .= $this->getProfileSection( wfMessage( 'custom-info-field1' )->escaped(), $profile_data['custom_1'], false );
		}
		if ( in_array( 'up_custom_2', $this->profile_visible_fields ) ) {
			$custom_output .= $this->getProfileSection( wfMessage( 'custom-info-field2' )->escaped(), $profile_data['custom_2'], false );
		}
		if ( in_array( 'up_custom_3', $this->profile_visible_fields ) ) {
			$custom_output .= $this->getProfileSection( wfMessage( 'custom-info-field3' )->escaped(), $profile_data['custom_3'], false );
		}
		if ( in_array( 'up_custom_4', $this->profile_visible_fields ) ) {
			$custom_output .= $this->getProfileSection( wfMessage( 'custom-info-field4' )->escaped(), $profile_data['custom_4'], false );
		}

		$output = '';
		if ( $joined_data ) {
			$output .= '<div class="user-section-heading">
				<div class="user-section-title">' .
					wfMessage( 'custom-info-title' )->escaped() .
				'</div>
				<div class="user-section-actions">
					<div class="action-right">';
			if ( $this->viewingUser->getName() == $this->profileOwner->getName() ) {
				$output .= '<a href="' . htmlspecialchars( $edit_info_link->getFullURL() ) . '/custom">' .
					wfMessage( 'user-edit-this' )->escaped() . '</a>';
			}
			$output .= '</div>
					<div class="visualClear"></div>
				</div>
			</div>
			<div class="visualClear"></div>
			<div class="profile-info-container">' .
				$custom_output .
			'</div>';
		} elseif ( $this->viewingUser->getName() == $this->profileOwner->getName() ) {
			$output .= '<div class="user-section-heading">
				<div class="user-section-title">' .
					wfMessage( 'custom-info-title' )->escaped() .
				'</div>
				<div class="user-section-actions">
					<div class="action-right">
						<a href="' . htmlspecialchars( $edit_info_link->getFullURL() ) . '/custom">' .
							wfMessage( 'user-edit-this' )->escaped() .
						'</a>
					</div>
					<div class="visualClear"></div>
				</div>
			</div>
			<div class="visualClear"></div>
			<div class="no-info-container">' .
				wfMessage( 'custom-no-info' )->escaped() .
			'</div>';
		}

		return $output;
	}*/

	/**
	 * Get the interests (favorite movies, TV shows, music, etc.) for a given
	 * user.
	 *
	 * @return string HTML
	 */
	function getInterests() {
		global $wgUserProfileDisplay;

		if ( $wgUserProfileDisplay['interests'] == false ) {
			return '';
		}

		$this->initializeProfileData();

		$profile_data = $this->profile_data;
		$joined_data = $profile_data['movies'] . $profile_data['tv'] .
						$profile_data['music'] . $profile_data['books'] .
						$profile_data['video_games'] .
						$profile_data['magazines'] . $profile_data['drinks'] .
						$profile_data['snacks'];
		$edit_info_link = SpecialPage::getTitleFor( 'UpdateProfile' );

		$interests_output = '';
		if ( in_array( 'up_movies', $this->profile_visible_fields ) ) {
			$interests_output .= $this->getProfileSection( wfMessage( 'other-info-movies' )->escaped(), $profile_data['movies'], false );
		}
		if ( in_array( 'up_tv', $this->profile_visible_fields ) ) {
			$interests_output .= $this->getProfileSection( wfMessage( 'other-info-tv' )->escaped(), $profile_data['tv'], false );
		}
		if ( in_array( 'up_music', $this->profile_visible_fields ) ) {
			$interests_output .= $this->getProfileSection( wfMessage( 'other-info-music' )->escaped(), $profile_data['music'], false );
		}
		if ( in_array( 'up_books', $this->profile_visible_fields ) ) {
			$interests_output .= $this->getProfileSection( wfMessage( 'other-info-books' )->escaped(), $profile_data['books'], false );
		}
		if ( in_array( 'up_video_games', $this->profile_visible_fields ) ) {
			$interests_output .= $this->getProfileSection( wfMessage( 'other-info-video-games' )->escaped(), $profile_data['video_games'], false );
		}
		if ( in_array( 'up_magazines', $this->profile_visible_fields ) ) {
			$interests_output .= $this->getProfileSection( wfMessage( 'other-info-magazines' )->escaped(), $profile_data['magazines'], false );
		}
		if ( in_array( 'up_snacks', $this->profile_visible_fields ) ) {
			$interests_output .= $this->getProfileSection( wfMessage( 'other-info-snacks' )->escaped(), $profile_data['snacks'], false );
		}
		if ( in_array( 'up_drinks', $this->profile_visible_fields ) ) {
			$interests_output .= $this->getProfileSection( wfMessage( 'other-info-drinks' )->escaped(), $profile_data['drinks'], false );
		}

		$output = '';
		if ( $joined_data ) {
			$output .= '<div class="user-section-heading">
				<div class="user-section-title">' .
					wfMessage( 'other-info-title' )->escaped() .
				'</div>
				<div class="user-section-actions">
					<div class="action-right">';
			if ( $this->viewingUser->getName() == $this->profileOwner->getName() ) {
				$output .= '<a href="' . htmlspecialchars( $edit_info_link->getFullURL() ) . '/personal">' .
					wfMessage( 'user-edit-this' )->escaped() . '</a>';
			}
			$output .= '</div>
					<div class="visualClear"></div>
				</div>
			</div>
			<div class="visualClear"></div>
			<div class="profile-info-container">' .
				$interests_output .
			'</div>';
		} elseif ( $this->isOwner() ) {
			$output .= '<div class="user-section-heading">
				<div class="user-section-title">' .
					wfMessage( 'other-info-title' )->escaped() .
				'</div>
				<div class="user-section-actions">
					<div class="action-right">
						<a href="' . htmlspecialchars( $edit_info_link->getFullURL() ) . '/personal">' .
							wfMessage( 'user-edit-this' )->escaped() .
						'</a>
					</div>
					<div class="visualClear"></div>
				</div>
			</div>
			<div class="visualClear"></div>
			<div class="no-info-container">' .
				wfMessage( 'other-no-info' )->escaped() .
			'</div>';
		}
		return $output;
	}

	/**
	 * Get the header for the social profile page, which includes the user's
	 * points and user level (if enabled in the site configuration) and lots
	 * more.
	 *
	 * @return string HTML suitable for output
	 */
	function getProfileHeader() {
		global $wgUserLevels;

		$context = $this->getContext();
		$language = $context->getLanguage();

		$stats = new UserStats( $this->profileOwner );
		$stats_data = $stats->getUserStats();
		$user_level = new UserLevel( $stats_data['points'] );
		$level_link = Title::makeTitle( NS_HELP, wfMessage( 'user-profile-userlevels-link' )->inContentLanguage()->text() );

		$this->initializeProfileData();
		$profile_data = $this->profile_data;

		// Safe URLs
		$update_profile = SpecialPage::getTitleFor( 'UpdateProfile' );
		$watchlist = SpecialPage::getTitleFor( 'Watchlist' );
		$contributions = SpecialPage::getTitleFor( 'Contributions', $this->profileOwner->getName() );
		$send_message = SpecialPage::getTitleFor( 'UserBoard' );
		$upload_avatar = SpecialPage::getTitleFor( 'UploadAvatar' );
		$user_social_profile = Title::makeTitle( NS_USER_PROFILE, $this->profileOwner->getName() );
		$user_wiki = Title::makeTitle( NS_USER_WIKI, $this->profileOwner->getName() );

		if ( !$this->profileOwner->isAnon() ) {
			$relationship = UserRelationship::getUserRelationshipByID(
				$this->profileOwner,
				$this->viewingUser
			);
		}
		$avatar = new wAvatar( $this->profileOwner->getId(), 'l' );

		$logger = LoggerFactory::getInstance( 'SocialProfile' );
		$logger->debug( "profile type: {user_profile_type} \n", [
			'user_profile_type' => $profile_data['user_page_type']
		] );

		$output = '';

		// Show the link for changing user page type for the user whose page
		// it is
		if ( $this->isOwner() ) {
			$toggle_title = SpecialPage::getTitleFor( 'ToggleUserPage' );
			// Cast it to an int because PHP is stupid.
			if (
				(int)$profile_data['user_page_type'] == 1 ||
				$profile_data['user_page_type'] === ''
			) {
				$toggleMessage = wfMessage( 'user-type-toggle-old' )->escaped();
			} else {
				$toggleMessage = wfMessage( 'user-type-toggle-new' )->escaped();
			}
			$output .= '<div id="profile-toggle-button">
				<a href="' . htmlspecialchars( $toggle_title->getFullURL() ) . '" rel="nofollow">' .
					$toggleMessage . '</a>
			</div>';
		}

		$output .= '<div id="profile-image">' . $avatar->getAvatarURL();
		// Expose the link to the avatar removal page in the UI when the user has
		// uploaded a custom avatar
		$canRemoveOthersAvatars = $this->viewingUser->isAllowed( 'avatarremove' );
		if ( !$avatar->isDefault() && ( $canRemoveOthersAvatars || $this->isOwner() ) ) {
			// Different URLs for privileged and regular users
			// Need to specify the user for people who are able to remove anyone's avatar
			// via the special page; for regular users, it doesn't matter because they
			// can't remove anyone else's but their own avatar via RemoveAvatar
			if ( $canRemoveOthersAvatars ) {
				$removeAvatarURL = SpecialPage::getTitleFor( 'RemoveAvatar', $this->profileOwner->getName() )->getFullURL();
			} else {
				$removeAvatarURL = SpecialPage::getTitleFor( 'RemoveAvatar' )->getFullURL();
			}
			$output .= '<p><a href="' . htmlspecialchars( $removeAvatarURL ) . '" rel="nofollow">' .
					wfMessage( 'user-profile-remove-avatar' )->escaped() . '</a>
			</p>';
		}
		$output .= '</div>';

		$output .= '<div id="profile-right">';

		$output .= '<div id="profile-title-container">
				<div id="profile-title">' .
					htmlspecialchars( $this->profileOwner->getName() ) .
				'</div>';
		$output .= '<div class="visualClear"></div>
			</div>
			<div class="profile-actions">';

		$profileLinks = [];
		if ( $this->isOwner() ) {
			$profileLinks['user-edit-profile'] =
				'<a href="' . htmlspecialchars( $update_profile->getFullURL() ) . '">' . wfMessage( 'user-edit-profile' )->escaped() . '</a>';
			$profileLinks['user-upload-avatar'] =
				'<a href="' . htmlspecialchars( $upload_avatar->getFullURL() ) . '">' . wfMessage( 'user-upload-avatar' )->escaped() . '</a>';
			$profileLinks['user-watchlist'] =
				'<a href="' . htmlspecialchars( $watchlist->getFullURL() ) . '">' . wfMessage( 'user-watchlist' )->escaped() . '</a>';
		} elseif ( $this->viewingUser->isRegistered() ) {
			// Support for friendly-by-default URLs (T191157)
			$add_friend = SpecialPage::getTitleFor(
				'AddRelationship',
				$this->profileOwner->getName() . '/friend'
			);
			$remove_relationship = SpecialPage::getTitleFor(
				'RemoveRelationship',
				$this->profileOwner->getName()
			);

			if ( $relationship == false ) {
				$profileLinks['user-add-friend'] =
					'<a href="' . htmlspecialchars( $add_friend->getFullURL() ) . '" rel="nofollow">' . wfMessage( 'user-add-friend' )->escaped() . '</a>';
			} else {
				if ( $relationship == 1 ) {
					$profileLinks['user-remove-friend'] =
						'<a href="' . htmlspecialchars( $remove_relationship->getFullURL() ) . '">' . wfMessage( 'user-remove-friend' )->escaped() . '</a>';
				}
			}

			global $wgUserBoard;
			if ( $wgUserBoard ) {
				$profileLinks['user-send-message'] =
					'<a href="' . htmlspecialchars( $send_message->getFullURL( [
						'user' => $this->viewingUser->getName(),
						'conv' => $this->profileOwner->getName()
					] ) ) . '" rel="nofollow">' .
					wfMessage( 'user-send-message' )->escaped() . '</a>';
			}
		}

		$profileLinks['user-contributions'] =
			'<a href="' . htmlspecialchars( $contributions->getFullURL() ) . '" rel="nofollow">' .
				wfMessage( 'user-contributions' )->escaped() . '</a>';

		// Links to User:user_name from User_profile:
		if (
			$this->getTitle()->getNamespace() == NS_USER_PROFILE &&
			$this->profile_data['actor'] &&
			$this->profile_data['user_page_type'] == 0
		) {
			$profileLinks['user-page-link'] =
				'<a href="' . htmlspecialchars( $this->profileOwner->getUserPage()->getFullURL() ) . '" rel="nofollow">' .
					wfMessage( 'user-page-link' )->escaped() . '</a>';
		}

		// Links to User:user_name from User_profile:
		if (
			$this->getTitle()->getNamespace() == NS_USER &&
			$this->profile_data['actor'] &&
			$this->profile_data['user_page_type'] == 0
		) {
			$profileLinks['user-social-profile-link'] =
				'<a href="' . htmlspecialchars( $user_social_profile->getFullURL() ) . '" rel="nofollow">' .
					wfMessage( 'user-social-profile-link' )->escaped() . '</a>';
		}

		if (
			$this->getTitle()->getNamespace() == NS_USER && (
				!$this->profile_data['actor'] ||
				$this->profile_data['user_page_type'] == 1
			)
		) {
			$profileLinks['user-wiki-link'] =
				'<a href="' . htmlspecialchars( $user_wiki->getFullURL() ) . '" rel="nofollow">' .
					wfMessage( 'user-wiki-link' )->escaped() . '</a>';
		}

		// Provide a hook point for adding links to the profile header
		// or maybe even removing them
		// @see https://phabricator.wikimedia.org/T152930
		Hooks::run( 'UserProfileGetProfileHeaderLinks', [ $this, &$profileLinks ] );

		$output .= $language->pipeList( $profileLinks );
		$output .= '</div>

		</div>';

		return $output;
	}

	/**
	 * Get the relationships for a given user.
	 *
	 * @param int $rel_type
	 * - 1 for friends
	 *
	 * @return string
	 */
	function getRelationships( $rel_type ) {
		global $wgUserProfileDisplay;

		$cache = MediaWikiServices::getInstance()->getMainWANObjectCache();
		$context = $this->getContext();
		$language = $context->getLanguage();

		// If not enabled in site settings, don't display
		if ( $rel_type == 1 ) {
			if ( $wgUserProfileDisplay['friends'] == false ) {
				return '';
			}
		} else {
			return '';
		}

		$output = ''; // Prevent E_NOTICE

		$count = 4;
		$key = $cache->makeKey( 'relationship', 'profile', 'actor_id', "{$this->profileOwner->getActorId()}-{$rel_type}" );
		$data = $cache->get( $key );

		// Try cache
		if ( !$data ) {
			$listLookup = new RelationshipListLookup( $this->profileOwner, $count );
			$friends = $listLookup->getRelationshipList( $rel_type );
			$cache->set( $key, $friends );
		} else {
			$logger = LoggerFactory::getInstance( 'SocialProfile' );
			$logger->debug( "Got profile relationship type {rel_type} for user {user_name} from cache\n", [
				'rel_type' => $rel_type,
				'user_name' => $this->profileOwner->getName()
			] );

			$friends = $data;
		}

		$stats = new UserStats( $this->profileOwner );
		$stats_data = $stats->getUserStats();

		if ( $rel_type == 1 ) {
			$relationship_count = $stats_data['friend_count'];
			$relationship_title = wfMessage( 'user-friends-title' )->escaped();
		} else {
		}

		if ( count( $friends ) > 0 ) {
			$x = 1;
			$per_row = 4;

			$output .= '<div class="user-section-heading">
				<div class="user-section-title">' . $relationship_title . '</div>
				<div class="user-section-actions">
					<div class="action-right">';
			if ( intval( $relationship_count ) > 4 ) {
				// Use the friendlier URLs here by default (T191157)
				$rel_type_name = ( $rel_type == 1 ? 'friends' : 'foes' );
				$view_all_title = SpecialPage::getTitleFor(
					'ViewRelationships',
					$this->profileOwner->getName() . '/' . $rel_type_name
				);
				$output .= '<a href="' . htmlspecialchars( $view_all_title->getFullURL() ) .
					'" rel="nofollow">' . wfMessage( 'user-view-all' )->escaped() . '</a>';
			}
			$output .= '</div>
					<div class="action-left">';
			if ( intval( $relationship_count ) > 4 ) {
				$output .= wfMessage( 'user-count-separator', $per_row, $relationship_count )->escaped();
			} else {
				$output .= wfMessage( 'user-count-separator', $relationship_count, $relationship_count )->escaped();
			}
			$output .= '</div>
				</div>
				<div class="visualClear"></div>
			</div>
			<div class="visualClear"></div>
			<div class="user-relationship-container">';

			foreach ( $friends as $friend ) {
				$user = User::newFromActorId( $friend['actor'] );
				if ( !$user ) {
					continue;
				}
				$avatar = new wAvatar( $user->getId(), 'ml' );

				// Chop down username that gets displayed
				// @phan-suppress-next-line SecurityCheck-DoubleEscaped T290624
				$user_name = htmlspecialchars( $language->truncateForVisual( $user->getName(), 9, '..' ) );

				$output .= "<a href=\"" . htmlspecialchars( $user->getUserPage()->getFullURL() ) .
					"\" title=\"" . htmlspecialchars( $user->getName() ) . "\" rel=\"nofollow\">
					{$avatar->getAvatarURL()}<br />
					{$user_name}
				</a>";

				if ( $x == count( $friends ) || $x != 1 && $x % $per_row == 0 ) {
					$output .= '<div class="visualClear"></div>';
				}

				$x++;
			}

			$output .= '</div>';
		}

		return $output;
	}

	/**
	 * Gets the recent social activity for a given user.
	 *
	 * @return string HTML
	 */
	function getActivity() {
		global $wgUserProfileDisplay;

		// If not enabled in site settings, don't display
		if ( $wgUserProfileDisplay['activity'] == false ) {
			return '';
		}

		$output = '';

		$limit = 8;
		$rel = new UserActivity( $this->profileOwner, 'user', $limit );

		/**
		 * Get all relationship activity
		 */
		$activity = $rel->getActivityList();

		if ( $activity ) {
			$output .= '<div class="user-section-heading">
				<div class="user-section-title">' .
					wfMessage( 'user-recent-activity-title' )->escaped() .
				'</div>
				<div class="user-section-actions">
					<div class="action-right">
					</div>
					<div class="visualClear"></div>
				</div>
			</div>
			<div class="visualClear"></div>';

			$x = 1;

			if ( count( $activity ) < $limit ) {
				$style_limit = count( $activity );
			} else {
				$style_limit = $limit;
			}

			$items_html_type = [];

			foreach ( $activity as $item ) {
				$item_html = '';
				$title = Title::makeTitle( $item['namespace'], $item['pagetitle'] );
				$user_title = Title::makeTitle( NS_USER, $item['username'] );

				if ( $x < $style_limit ) {
					$class = 'activity-item';
				} else {
					$class = 'activity-item-bottom';
				}

				$userActivityIcon = new UserActivityIcon( $item['type'] );
				$icon = $userActivityIcon->getIconHTML();
				$item_html .= "<div class=\"{$class}\">" . $icon;

				$viewGift = SpecialPage::getTitleFor( 'ViewGift' );

				switch ( $item['type'] ) {
					case 'edit':
						$item_html .= wfMessage( 'user-recent-activity-edit' )->escaped() . " {$page_link} {$item_time}
							<div class=\"item\">";
						$item_html .= '</div>';
						break;
					case 'friend':
						$item_html .= wfMessage( 'user-recent-activity-friend' )->escaped() .
							" <b>{$user_link_2}</b> {$item_time}";
						break;
					case 'system_message':
						$item_html .= "{$item['comment']} {$item_time}";
						break;
					case 'user_message':
						$item_html .= wfMessage( 'user-recent-activity-user-message' )->escaped() .
							" <b><a href=\"" .
								htmlspecialchars(
									SpecialPage::getTitleFor( 'UserBoard' )->getFullURL( [ 'user' => $user_title_2->getText() ] )
								) .
								// @phan-suppress-next-line SecurityCheck-DoubleEscaped Not sure but might be a false alarm (about $item['comment'])
								"\" rel=\"nofollow\">" . htmlspecialchars( $item['comment'] ) . "</a></b>  {$item_time}
								<div class=\"item\">
								\"{$item['namespace']}\"
								</div>";
						break;
				}

				$item_html .= '</div>';

				if ( $x <= $limit ) {
					$items_html_type['all'][] = $item_html;
				}
				$items_html_type[$item['type']][] = $item_html;

				$x++;
			}

			$by_type = '';
			foreach ( $items_html_type['all'] as $item ) {
				$by_type .= $item;
			}
			$output .= "<div id=\"recent-all\">$by_type</div>";
		}

		return $output;
	}

	/**
	 * Get the user board for a given user.
	 *
	 * @return string
	 */
	function getUserBoard() {
		global $wgUserProfileDisplay;

		// Anonymous users cannot have user boards
		if ( $this->profileOwner->isAnon() ) {
			return '';
		}

		// Don't display anything if user board on social profiles isn't
		// enabled in site configuration
		if ( $wgUserProfileDisplay['board'] == false ) {
			return '';
		}

		$output = ''; // Prevent E_NOTICE

		$listLookup = new RelationshipListLookup( $this->profileOwner, 4 );
		$friends = $listLookup->getFriendList();

		$stats = new UserStats( $this->profileOwner );
		$stats_data = $stats->getUserStats();
		$total = $stats_data['user_board'];

		// If the user is viewing their own profile or is allowed to delete
		// board messages, add the amount of private messages to the total
		// sum of board messages.
		if (
			$this->viewingUser->getName() == $this->profileOwner->getName() ||
			$this->viewingUser->isAllowed( 'userboard-delete' )
		) {
			$total = $total + $stats_data['user_board_priv'];
		}

		$output .= '<div class="user-section-heading">
			<div class="user-section-title">' .
				wfMessage( 'user-board-title' )->escaped() .
			'</div>
			<div class="user-section-actions">
				<div class="action-right">';
		if ( $this->viewingUser->getName() == $this->profileOwner->getName() ) {
			if ( $friends ) {
				$output .= '<a href="' .
					htmlspecialchars(
						SpecialPage::getTitleFor( 'SendBoardBlast' )->getFullURL()
					) . '">' .
					wfMessage( 'user-send-board-blast' )->escaped() . '</a>';
			}
			if ( $total > 10 ) {
				$output .= wfMessage( 'pipe-separator' )->escaped();
			}
		}
		if ( $total > 10 ) {
			$output .= '<a href="' .
				htmlspecialchars(
					SpecialPage::getTitleFor( 'UserBoard' )->getFullURL( [ 'user' => $this->profileOwner->getName() ] )
				) . '">' .
				wfMessage( 'user-view-all' )->escaped() . '</a>';
		}
		$output .= '</div>
				<div class="action-left">';
		if ( $total > 10 ) {
			$output .= wfMessage( 'user-count-separator', '10', $total )->escaped();
		} elseif ( $total > 0 ) {
			$output .= wfMessage( 'user-count-separator', $total, $total )->escaped();
		}
		$output .= '</div>
				<div class="visualClear"></div>
			</div>
		</div>
		<div class="visualClear"></div>';

		if ( $this->viewingUser->getName() !== $this->profileOwner->getName() ) {
			if ( $this->viewingUser->isRegistered() && !$this->viewingUser->isBlocked() ) {
				// @todo FIXME: This code exists in an almost identical form in
				// ../../UserBoard/incldues/specials/SpecialUserBoard.php
				$url = htmlspecialchars(
					SpecialPage::getTitleFor( 'UserBoard' )->getFullURL( [
						'user' => $this->profileOwner->getName(),
						'action' => 'send'
					] ),
					ENT_QUOTES
				);
				$output .= '<div class="user-page-message-form">
					<form id="board-post-form" action="' . $url . '" method="post">
						<input type="hidden" id="user_name_to" name="user_name_to" value="' . htmlspecialchars( $this->profileOwner->getName(), ENT_QUOTES ) . '" />
						<span class="profile-board-message-type">' .
							wfMessage( 'userboard_messagetype' )->escaped() .
						'</span>
						<select id="message_type" name="message_type">
							<option value="0">' .
								wfMessage( 'userboard_public' )->escaped() .
							'</option>
							<option value="1">' .
								wfMessage( 'userboard_private' )->escaped() .
							'</option>
						</select><p>
						<textarea name="message" id="message" cols="43" rows="4"></textarea>
						<div class="user-page-message-box-button">
							<input type="submit" value="' . wfMessage( 'userboard_sendbutton' )->escaped() . '" class="site-button" />
						</div>' .
						Html::hidden( 'wpEditToken', $this->viewingUser->getEditToken() ) .
					'</form></div>';
			} elseif ( $this->viewingUser->isRegistered() && $this->viewingUser->isBlocked() ) {
				// Show a better i18n message for registered users who are blocked
				// @see https://phabricator.wikimedia.org/T266918
				$output .= '<div class="user-page-message-form-blocked">' .
					wfMessage( 'user-board-blocked-message' )->escaped() .
				'</div>';
			} else {
				$output .= '<div class="user-page-message-form">' .
					wfMessage( 'user-board-login-message' )->parse() .
				'</div>';
			}
		}

		$output .= '<div id="user-page-board">';
		$b = new UserBoard( $this->viewingUser );
		$output .= $b->displayMessages( $this->profileOwner, 0, 10 );
		$output .= '</div>';

		return $output;
	}

	/**
	 * Initialize UserProfile data for the given user if that hasn't been done
	 * already.
	 */
	private function initializeProfileData() {
		if ( !$this->profile_data ) {
			$profile = new UserProfile( $this->profileOwner );
			$this->profile_data = $profile->getProfile();
		}
	}
}
