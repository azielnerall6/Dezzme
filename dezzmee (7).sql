-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Aug 01, 2026 at 04:50 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `dezzmee`
--

-- --------------------------------------------------------

--
-- Table structure for table `audios`
--

CREATE TABLE `audios` (
  `id` int(11) NOT NULL,
  `uploader_id` int(10) UNSIGNED NOT NULL,
  `title` varchar(150) NOT NULL,
  `artist` varchar(150) DEFAULT NULL,
  `album` varchar(150) DEFAULT NULL,
  `file_name` varchar(255) NOT NULL,
  `cover_art` varchar(255) DEFAULT NULL,
  `duration` smallint(5) UNSIGNED DEFAULT NULL,
  `is_original` tinyint(1) NOT NULL DEFAULT 0,
  `copyright_status` enum('original','licensed','unknown') NOT NULL DEFAULT 'unknown',
  `visibility` enum('public','private') NOT NULL DEFAULT 'public',
  `category` varchar(60) DEFAULT NULL,
  `tags` varchar(255) DEFAULT NULL,
  `waveform_data` text DEFAULT NULL,
  `play_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `save_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `repost_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `audios`
--

INSERT INTO `audios` (`id`, `uploader_id`, `title`, `artist`, `album`, `file_name`, `cover_art`, `duration`, `is_original`, `copyright_status`, `visibility`, `category`, `tags`, `waveform_data`, `play_count`, `save_count`, `repost_count`, `created_at`) VALUES
(1, 7, 'After all this time - Patrick Jordan Patrikios', NULL, NULL, 'audio_1785334669_9928.mp3', NULL, NULL, 1, 'unknown', 'public', NULL, NULL, NULL, 0, 0, 0, '2026-07-29 14:17:49');

-- --------------------------------------------------------

--
-- Table structure for table `audio_saves`
--

CREATE TABLE `audio_saves` (
  `id` int(11) NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `audio_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `blocks`
--

CREATE TABLE `blocks` (
  `id` int(11) UNSIGNED NOT NULL,
  `blocker_id` int(11) UNSIGNED NOT NULL,
  `blocked_id` int(11) UNSIGNED NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `collections`
--

CREATE TABLE `collections` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `is_public` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `collections`
--

INSERT INTO `collections` (`id`, `user_id`, `name`, `created_at`, `is_public`) VALUES
(4, 3, 'Cardi B meme', '2026-05-28 04:34:05', 1),
(7, 2, 'Funny memes', '2026-06-10 14:07:57', 1),
(13, 7, 'Meme', '2026-07-20 14:13:54', 0);

-- --------------------------------------------------------

--
-- Table structure for table `collection_items`
--

CREATE TABLE `collection_items` (
  `id` int(11) NOT NULL,
  `collection_id` int(11) NOT NULL,
  `meme_id` int(11) NOT NULL,
  `added_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `collection_items`
--

INSERT INTO `collection_items` (`id`, `collection_id`, `meme_id`, `added_at`) VALUES
(5, 4, 40, '2026-05-28 04:34:25'),
(6, 4, 54, '2026-05-28 04:34:29'),
(15, 7, 3, '2026-06-10 14:08:06'),
(17, 7, 40, '2026-06-10 14:08:20'),
(18, 7, 1, '2026-06-10 14:08:25'),
(19, 7, 66, '2026-06-10 14:08:31'),
(20, 4, 11, '2026-06-17 07:36:01'),
(23, 13, 67, '2026-07-20 14:14:09'),
(24, 13, 125, '2026-07-20 14:14:27'),
(26, 13, 124, '2026-07-29 13:09:59'),
(27, 13, 140, '2026-07-30 07:29:26');

-- --------------------------------------------------------

--
-- Table structure for table `comments`
--

CREATE TABLE `comments` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `meme_id` int(10) UNSIGNED NOT NULL,
  `text` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `content`
--

CREATE TABLE `content` (
  `id` int(10) UNSIGNED NOT NULL,
  `creator_id` int(10) UNSIGNED NOT NULL,
  `type` enum('sound','link','meme','post') NOT NULL DEFAULT 'post',
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `file_url` varchar(500) DEFAULT NULL,
  `likes` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `views` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `comments` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `content_admin_log`
--

CREATE TABLE `content_admin_log` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `content_type` enum('meme','group_post','pod_discussion','comment','message') NOT NULL,
  `content_id` int(10) UNSIGNED NOT NULL,
  `page_user_id` int(10) UNSIGNED NOT NULL,
  `acting_admin_id` int(10) UNSIGNED NOT NULL,
  `action` varchar(30) NOT NULL DEFAULT 'create',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `content_reports`
--

CREATE TABLE `content_reports` (
  `id` int(10) UNSIGNED NOT NULL,
  `reporter_id` int(10) UNSIGNED NOT NULL,
  `content_type` enum('meme','comment','message') NOT NULL,
  `content_id` int(10) UNSIGNED NOT NULL,
  `content_owner_id` int(10) UNSIGNED DEFAULT NULL,
  `reason` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('pending','under_review','resolved','dismissed') NOT NULL DEFAULT 'pending',
  `admin_notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `content_reports`
--

INSERT INTO `content_reports` (`id`, `reporter_id`, `content_type`, `content_id`, `content_owner_id`, `reason`, `description`, `status`, `admin_notes`, `created_at`, `updated_at`) VALUES
(1, 3, 'meme', 4, NULL, 'Misinformation or Fake News', NULL, 'pending', NULL, '2026-06-23 08:10:39', '2026-06-23 08:10:39'),
(2, 3, 'message', 4, 5, 'Sharing Private Information', NULL, 'pending', NULL, '2026-06-23 09:17:55', '2026-06-23 09:17:55'),
(3, 16, 'meme', 85, 1, 'Misinformation or Fake News', NULL, 'pending', NULL, '2026-07-15 09:30:56', '2026-07-15 09:30:56'),
(4, 16, 'meme', 68, 8, 'Spam or Clickbait', NULL, 'pending', NULL, '2026-07-15 09:43:32', '2026-07-15 09:43:32'),
(5, 3, 'meme', 6, 4, 'Harassment or Cyberbullying', NULL, 'pending', NULL, '2026-07-15 13:24:39', '2026-07-15 13:24:39'),
(6, 7, 'meme', 18, 1, 'Self-Harm or Suicide Content', NULL, 'pending', NULL, '2026-07-22 17:15:22', '2026-07-22 17:15:22'),
(7, 7, 'meme', 68, 8, 'Disturbing or Upsetting Content', NULL, 'pending', NULL, '2026-07-22 17:16:30', '2026-07-22 17:16:30');

-- --------------------------------------------------------

--
-- Table structure for table `downloads`
--

CREATE TABLE `downloads` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `meme_id` int(11) NOT NULL,
  `downloaded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `favorites`
--

CREATE TABLE `favorites` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `meme_id` int(10) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `favorites`
--

INSERT INTO `favorites` (`id`, `user_id`, `meme_id`, `created_at`) VALUES
(18, 3, 1, '2026-03-23 12:36:08'),
(29, 2, 6, '2026-03-28 05:33:56'),
(35, 3, 54, '2026-03-28 08:03:23'),
(36, 4, 54, '2026-03-28 19:26:03'),
(41, 4, 6, '2026-03-30 09:35:16'),
(42, 4, 40, '2026-03-30 11:38:12'),
(43, 3, 40, '2026-04-02 11:03:56'),
(79, 2, 66, '2026-04-20 02:52:42'),
(87, 2, 1, '2026-04-20 04:08:32'),
(99, 2, 19, '2026-04-20 06:07:38'),
(104, 7, 40, '2026-04-20 07:30:24'),
(108, 8, 11, '2026-04-20 17:34:32'),
(111, 8, 19, '2026-04-20 18:36:48'),
(112, 3, 67, '2026-04-20 19:03:34'),
(114, 7, 3, '2026-04-21 17:25:58'),
(115, 7, 38, '2026-04-21 17:26:04'),
(116, 7, 1, '2026-04-21 17:26:06'),
(118, 1, 66, '2026-04-23 06:14:34'),
(124, 1, 11, '2026-04-23 09:59:22'),
(131, 2, 2, '2026-04-30 03:58:36'),
(132, 2, 40, '2026-04-30 03:58:47'),
(133, 2, 68, '2026-04-30 08:13:37'),
(137, 7, 85, '2026-05-05 14:21:18'),
(138, 7, 54, '2026-05-05 16:21:26'),
(160, 1, 38, '2026-05-20 11:36:03'),
(170, 2, 7, '2026-05-23 08:39:20'),
(171, 2, 85, '2026-05-23 11:27:29'),
(172, 7, 16, '2026-05-30 05:27:54'),
(173, 7, 48, '2026-05-30 05:29:37'),
(182, 2, 3, '2026-06-10 13:07:33'),
(187, 7, 7, '2026-07-18 13:21:28'),
(189, 7, 6, '2026-07-19 04:35:10'),
(190, 7, 67, '2026-07-19 06:04:35'),
(191, 7, 39, '2026-07-19 08:16:55'),
(192, 7, 11, '2026-07-19 08:17:50'),
(193, 3, 11, '2026-07-19 08:57:13'),
(196, 3, 124, '2026-07-20 15:14:28'),
(197, 7, 100, '2026-07-22 16:21:38'),
(199, 7, 120, '2026-07-23 09:18:02');

-- --------------------------------------------------------

--
-- Table structure for table `feedback`
--

CREATE TABLE `feedback` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `category` enum('bug','feature','general','abuse','other') NOT NULL DEFAULT 'general',
  `title` varchar(150) NOT NULL,
  `message` text NOT NULL,
  `screenshot` varchar(255) DEFAULT NULL,
  `status` enum('submitted','under_review','planned','in_development','completed','declined') NOT NULL DEFAULT 'submitted',
  `is_pinned` tinyint(1) NOT NULL DEFAULT 0,
  `is_duplicate_of` int(10) UNSIGNED DEFAULT NULL,
  `is_archived` tinyint(1) NOT NULL DEFAULT 0,
  `admin_notes` text DEFAULT NULL,
  `vote_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `allow_followup` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `feedback_status_log`
--

CREATE TABLE `feedback_status_log` (
  `id` int(10) UNSIGNED NOT NULL,
  `feedback_id` int(10) UNSIGNED NOT NULL,
  `old_status` varchar(30) DEFAULT NULL,
  `new_status` varchar(30) NOT NULL,
  `changed_by` int(10) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `feedback_votes`
--

CREATE TABLE `feedback_votes` (
  `id` int(10) UNSIGNED NOT NULL,
  `feedback_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `followers`
--

CREATE TABLE `followers` (
  `id` int(10) UNSIGNED NOT NULL,
  `follower_id` int(10) UNSIGNED NOT NULL,
  `following_id` int(10) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `followers`
--

INSERT INTO `followers` (`id`, `follower_id`, `following_id`, `created_at`) VALUES
(35, 2, 5, '2026-03-26 16:48:06'),
(75, 4, 2, '2026-03-30 11:43:10'),
(145, 3, 5, '2026-04-19 17:00:07'),
(150, 2, 1, '2026-04-20 04:56:03'),
(159, 2, 7, '2026-04-20 05:47:42'),
(163, 8, 7, '2026-04-20 13:52:26'),
(170, 8, 2, '2026-04-20 18:44:26'),
(171, 8, 5, '2026-04-20 18:44:44'),
(189, 2, 8, '2026-04-30 12:04:02'),
(190, 5, 7, '2026-05-07 02:43:17'),
(191, 5, 8, '2026-05-07 02:43:18'),
(194, 4, 5, '2026-05-07 02:53:06'),
(199, 1, 7, '2026-05-11 05:54:09'),
(219, 5, 2, '2026-05-16 15:06:31'),
(220, 5, 4, '2026-05-16 15:14:14'),
(222, 5, 1, '2026-05-16 15:15:59'),
(224, 4, 1, '2026-05-17 04:49:58'),
(229, 1, 2, '2026-05-19 11:39:47'),
(230, 1, 13, '2026-05-19 11:39:49'),
(231, 3, 2, '2026-05-19 11:41:09'),
(233, 4, 13, '2026-05-22 07:37:15'),
(237, 1, 8, '2026-05-23 18:02:51'),
(239, 3, 13, '2026-05-28 15:43:36'),
(250, 4, 7, '2026-05-29 12:25:41'),
(255, 8, 3, '2026-05-29 12:45:33'),
(256, 4, 3, '2026-05-29 12:47:54'),
(257, 1, 3, '2026-05-29 13:56:05'),
(261, 5, 3, '2026-05-29 16:59:31'),
(279, 2, 13, '2026-05-31 04:22:27'),
(280, 4, 8, '2026-06-03 07:52:51'),
(282, 8, 13, '2026-06-03 09:01:00'),
(287, 2, 3, '2026-06-07 19:04:05'),
(289, 5, 13, '2026-06-09 17:51:05'),
(300, 8, 4, '2026-06-15 10:49:04'),
(303, 8, 1, '2026-06-15 15:54:56'),
(309, 16, 13, '2026-06-16 06:36:37'),
(314, 2, 16, '2026-06-16 06:47:39'),
(318, 16, 2, '2026-06-16 08:02:07'),
(322, 16, 3, '2026-06-16 08:10:14'),
(323, 16, 1, '2026-06-16 09:19:51'),
(324, 16, 5, '2026-06-16 10:01:34'),
(325, 16, 8, '2026-06-16 16:56:54'),
(328, 13, 16, '2026-06-18 06:47:21'),
(333, 1, 4, '2026-06-22 10:00:58'),
(340, 1, 5, '2026-06-22 10:44:15'),
(342, 1, 16, '2026-06-22 10:51:37'),
(344, 13, 5, '2026-06-22 11:25:33'),
(345, 13, 2, '2026-06-22 11:33:23'),
(350, 2, 4, '2026-06-22 12:16:50'),
(354, 3, 7, '2026-06-27 04:50:17'),
(355, 3, 16, '2026-06-27 06:09:50'),
(356, 13, 7, '2026-06-27 06:11:49'),
(359, 8, 16, '2026-06-27 17:04:07'),
(363, 13, 8, '2026-07-02 08:28:28'),
(370, 7, 5, '2026-07-03 22:16:41'),
(371, 16, 7, '2026-07-05 17:52:22'),
(379, 3, 8, '2026-07-07 08:20:02'),
(382, 7, 16, '2026-07-09 15:56:28'),
(383, 7, 13, '2026-07-09 15:56:29'),
(387, 3, 1, '2026-07-15 13:28:39'),
(388, 3, 4, '2026-07-15 13:28:58'),
(389, 16, 4, '2026-07-16 06:18:34'),
(390, 4, 16, '2026-07-16 07:33:04'),
(391, 7, 2, '2026-07-18 11:53:25'),
(395, 5, 16, '2026-07-19 08:49:49'),
(400, 7, 3, '2026-07-23 10:40:56'),
(401, 7, 8, '2026-07-24 07:53:56'),
(404, 7, 4, '2026-07-24 09:43:57'),
(406, 7, 19, '2026-07-29 15:32:56'),
(407, 7, 1, '2026-08-01 08:16:55');

-- --------------------------------------------------------

--
-- Table structure for table `follows`
--

CREATE TABLE `follows` (
  `id` int(10) UNSIGNED NOT NULL,
  `follower_id` int(10) UNSIGNED NOT NULL,
  `following_id` int(10) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `groups`
--

CREATE TABLE `groups` (
  `id` int(10) UNSIGNED NOT NULL,
  `title` varchar(100) NOT NULL,
  `description` text NOT NULL,
  `group_pic` varchar(255) DEFAULT 'uploads/default_group.png',
  `created_by` int(10) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_adult` tinyint(1) NOT NULL DEFAULT 0,
  `custom_guidelines` text DEFAULT NULL,
  `guidelines` text DEFAULT NULL,
  `category` varchar(60) DEFAULT NULL,
  `banner_pic` varchar(255) DEFAULT NULL,
  `privacy` enum('public','private') DEFAULT 'public',
  `is_paused` tinyint(1) NOT NULL DEFAULT 0,
  `paused_at` datetime DEFAULT NULL,
  `paused_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `groups`
--

INSERT INTO `groups` (`id`, `title`, `description`, `group_pic`, `created_by`, `created_at`, `updated_at`, `is_adult`, `custom_guidelines`, `guidelines`, `category`, `banner_pic`, `privacy`, `is_paused`, `paused_at`, `paused_by`) VALUES
(1, 'Funny memes', 'Welcome to Funny Memes — a chill group where the internet’s funniest content lives. From viral memes and relatable jokes to random chaos that somehow makes sense, this is the place to scroll, laugh, and share. Post anything funny, react to what others share, and keep the vibe light. No seriousness here — just memes, jokes, GIFs, and good energy every day. If it makes people laugh, it belongs here. 😆', 'uploads/1774071374_hamster-with-big-eyes-meme-desktop-wallpaper-preview.jpg', 3, '2026-03-21 05:36:14', '2026-07-21 08:57:05', 0, NULL, NULL, 'Memes & Humor', '1782573175_Mischievous hammy.jpg', 'public', 0, NULL, NULL),
(2, 'Cardi B Fan page', 'Welcome to Cardi B Meme Pod—the place where Cardi B fans come together to laugh, create, and share the funniest memes on the internet. From iconic quotes and viral moments to hilarious edits, reaction images, and relatable fan jokes, this community is all about celebrating Cardi B through humor.  Whether you make your own memes or simply enjoy scrolling through them, you’re welcome here. Keep it creative, respectful, and fun, and let’s keep the timeline full of laughs.', '1782577754_download (35).jpg', 3, '2026-03-21 09:37:29', '2026-07-21 14:27:59', 1, '1. Keep it Cardi B related. Posts should focus on Cardi B, her music, career, fashion, performances, business ventures, or public appearances.\r\n2. Respect everyone. No harassment, bullying, hate speech, discrimination, or personal attacks toward members or public figures.\r\n3. No false information. Don’t spread fake news, edited screenshots presented as real, or unverified rumors. Clearly label speculation.\r\n4. No excessive self-promotion. Don’t spam your own pages, businesses, YouTube channels, or social accounts without permission from the admins.\r\n5. No NSFW or explicit content. Keep posts appropriate for the community. Avoid graphic or sexually explicit material.\r\n6. Protect privacy. Don’t share private or leaked photos, personal information, addresses, phone numbers, or other confidential content.\r\n7. Use respectful criticism. It’s okay to disagree with Cardi B’s music or opinions, but keep discussions civil and constructive.\r\n8. No duplicate or repetitive posts. Check recent posts before sharing breaking news, photos, or videos that have already been posted.\r\n9. Follow copyright rules. Share official links whenever possible, and don’t upload pirated music, albums, or copyrighted content illegally.\r\n10. Follow admin decisions. Moderators may remove posts or comments that break the rules. Repeated violations can result in warnings, temporary mutes, or removal from the group.', NULL, 'Fandom', '1782573645_Cardi B  Money 🤑💰💵💸💶💴💷🌺🖤.jpg', 'public', 0, NULL, NULL),
(4, 'Bad Girls Club', 'me and you baddies ', '1782983021_Colón_.jpg', 8, '2026-04-20 18:00:16', '2026-07-17 16:10:29', 0, NULL, NULL, NULL, '1782983194_Leopard pink aesthetique.jpg', 'public', 1, '2026-07-17 17:10:29', 3),
(6, 'Claude', 'Claude is the ultimate meme hub for Claude AI fans, tech nerds, and anyone who\'s ever laughed at an AI giving a suspiciously thoughtful answer to a ridiculous question. From prompt fails and hallucination highlights to \"Claude said what?\" moments and comparisons with other AI models, this pod is where the Anthropic community comes to laugh, relate, and share the chaotic beauty of conversational AI. Whether you\'re a developer debugging at 3am or just someone who asked Claude to write a poem about their cat, you belong here.', 'uploads/1782581418_Claude Logo PNG Vector (SVG) Free Download (1).jpg', 7, '2026-06-27 17:30:18', '2026-07-31 12:13:59', 0, 'Keep all memes and posts related to AI, tech, Claude, Anthropic, or the broader world of large language models — off-topic content will be removed without warning. Always credit the original creator when reposting or remixing content. No hate speech, harassment, or personal attacks against any person, company, or AI model — roasting Claude\'s outputs is fine, being cruel to humans is not. Spam posting the same meme repeatedly is an instant removal. Keep things fun and good-natured — this is a comedy and appreciation space, not a debate battlefield. If you share a real Claude conversation screenshot, make sure it\'s genuinely funny or interesting, not just bait. No misinformation about Anthropic or Claude presented as fact — clearly label satire and parody posts with a relevant hashtag like #satire or #parody.', 'Keep all memes and posts related to AI, tech, Claude, Anthropic, or the broader world of large language models — off-topic content will be removed without warning. Always credit the original creator when reposting or remixing content. No hate speech, harassment, or personal attacks against any person, company, or AI model — roasting Claude\'s outputs is fine, being cruel to humans is not. Spam posting the same meme repeatedly is an instant removal. Keep things fun and good-natured — this is a com', 'Technology', '1782581532_Claude for Slack _ Claude.jpg', 'private', 0, NULL, NULL),
(7, 'Cat Internet', '🐱 Welcome to the Cat Internet Pod  Welcome to the Cat Internet Pod, the ultimate community for everyone who believes the internet simply wouldn’t be the same without cats. Whether you adore adorable kittens, hilarious cat memes, iconic viral videos, majestic floofs, mischievous troublemakers, or wholesome feline moments, you’ve found your home.  This pod celebrates every corner of cat culture—from famous internet cats and trending memes to heartwarming rescue stories, funny reactions, cute GIFs, and everything in between. Whether you’re sharing your own cat, discovering new feline celebrities, or laughing at the latest viral clip, there’s always something worth seeing.  Our members come together to post entertaining content, exchange advice, discuss cat behavior, recommend products, celebrate special occasions, and appreciate the personalities that make every cat unique. From sleepy loafs and dramatic zoomies to tiny kittens and senior companions, every cat has a place here.  What you’ll discover:  🐾 Funny cat memes and viral internet moments 😹 Hilarious videos, GIFs, and reaction images 📸 Photos of adorable cats from around the world 🐈 Your own cats, rescues, and daily adventures 💬 Friendly discussions about breeds, behavior, and care 🧶 Toys, accessories, and product recommendations ❤️ Heartwarming rescue and adoption stories 🎉 Community challenges, themed events, and photo contests 🌟 Famous internet cats and trending feline content 🎨 Cat artwork, edits, comics, and creative creations                           Whether your cat is playful, dramatic, chaotic, sleepy, elegant, or completely obsessed with cardboard boxes, we’d love to meet them. Every post helps make this pod a happier place for cat lovers everywhere.  Join the conversation, share your favorite feline moments, and celebrate the internet’s most beloved four-legged stars. Welcome to the Cat Internet Pod! 🐱💙', 'pod_6a5f74d9a15b2.jpg', 7, '2026-07-21 13:32:09', '2026-07-21 14:11:52', 0, NULL, NULL, 'Pets', 'pod_banner_6a5f74d9a1e7f.jpg', 'public', 0, NULL, NULL),
(8, 'Dezzme Official', 'Welcome to the Official Dezzme Pod\r\n\r\nWelcome to the Official Dezzme Pod — the central hub for everything happening across Dezzme. Whether you’re new to the platform or a long-time Dezzmer, this is the best place to stay informed, discover new features, connect with the community, and help shape the future of Dezzme.\r\n\r\nHere you’ll find official announcements, feature launches, platform updates, maintenance notices, safety information, community discussions, polls, events, tutorials, tips and tricks, creator spotlights, challenges, and sneak peeks at what’s coming next. We also use this pod to gather feedback from the community, so your ideas and suggestions can directly influence future improvements.\r\n\r\nMembers are encouraged to participate in discussions, ask questions, share constructive feedback, report bugs, vote in community polls, and engage respectfully with other Dezzmers. Whether you’re here to create memes, share videos, discover communities, or simply have fun, this pod is your direct connection to the Dezzme team and the wider community.\r\n\r\nWhat you’ll find here:\r\n\r\n• 📢 Official platform announcements and news\r\n• 🚀 New feature releases and beta previews\r\n• 🛠️ Maintenance updates and service notices\r\n• 🗳️ Community polls and feedback opportunities\r\n• 🎉 Official events, contests, and challenges\r\n• 💡 Tips, tutorials, and platform guides\r\n• ⭐️ Creator and community spotlights\r\n• 🐞 Bug reporting and known issue updates\r\n• ❓ Frequently Asked Questions and support resources\r\n• 🎁 Exclusive community surprises and special announcements\r\n\r\nThe Official Dezzme Pod is managed by the Dezzme team, and moderators may remove content or take action when necessary to keep discussions safe, productive, and enjoyable for everyone.\r\n\r\nThank you for being part of Dezzme. Every meme shared, conversation started, pod joined, and idea contributed helps make the platform better. We’re excited to build the future of Dezzme together with you.\r\n\r\nWelcome to the community — and welcome home. 💙', 'pod_6a5f77ba311e7.jpg', 7, '2026-07-21 13:44:26', '2026-07-21 14:30:31', 0, NULL, NULL, 'Dezzme Official', '', 'private', 0, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `group_activity_log`
--

CREATE TABLE `group_activity_log` (
  `id` int(10) UNSIGNED NOT NULL,
  `group_id` int(10) UNSIGNED NOT NULL,
  `actor_id` int(10) UNSIGNED NOT NULL,
  `target_user_id` int(10) UNSIGNED DEFAULT NULL,
  `action_type` varchar(50) NOT NULL,
  `details` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `group_activity_log`
--

INSERT INTO `group_activity_log` (`id`, `group_id`, `actor_id`, `target_user_id`, `action_type`, `details`, `created_at`) VALUES
(1, 6, 7, NULL, 'enable_adult', NULL, '2026-07-21 08:41:30'),
(2, 6, 7, NULL, 'disable_adult', NULL, '2026-07-21 08:41:56'),
(3, 1, 3, NULL, 'change_category', 'Uncategorized → Memes & Humor', '2026-07-21 08:57:05'),
(4, 2, 3, NULL, 'change_category', 'Uncategorized → Entertainment', '2026-07-21 09:05:00'),
(5, 6, 7, NULL, 'rename_group', 'Claude → Claudeeeeeeeeeeeeeeeeeeeeee', '2026-07-21 10:16:35'),
(6, 6, 7, NULL, 'rename_group', 'Claudeeeeeeeeeeeeeeeeeeeeee → Claude', '2026-07-21 10:17:30'),
(7, 6, 7, NULL, 'rename_group', 'Claude → Claudeeeeeeeeeee', '2026-07-21 10:22:51'),
(8, 6, 7, NULL, 'rename_group', 'Claudeeeeeeeeeee → Claude', '2026-07-21 10:24:38'),
(9, 6, 7, NULL, 'rename_group', 'Claude → Claudeeeeeeeeeeeeee', '2026-07-21 10:33:19'),
(10, 6, 7, NULL, 'rename_group', 'Claudeeeeeeeeeeeeee → Claudeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee', '2026-07-21 10:34:17'),
(11, 6, 7, NULL, 'rename_group', 'Claudeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee → Claude', '2026-07-21 10:35:24'),
(12, 7, 7, NULL, 'change_desc', NULL, '2026-07-21 13:41:27'),
(13, 8, 7, NULL, 'change_category', 'Other → Philosophy', '2026-07-21 13:52:45'),
(14, 8, 7, NULL, 'change_category', 'Philosophy → Dezzme Official', '2026-07-21 13:54:26'),
(15, 7, 7, NULL, 'change_category', 'Other → Cats', '2026-07-21 13:59:46'),
(16, 6, 7, NULL, 'change_category', 'Tech & Dev → Technology', '2026-07-21 14:10:10'),
(17, 7, 7, NULL, 'change_category', 'Cats → Pets', '2026-07-21 14:11:52'),
(18, 8, 7, NULL, 'make_private', NULL, '2026-07-21 14:14:48'),
(19, 8, 7, 3, 'approve_request', NULL, '2026-07-21 14:15:52'),
(20, 8, 7, NULL, 'pause_group', NULL, '2026-07-21 14:20:49'),
(21, 2, 3, NULL, 'change_category', 'Entertainment → Fandoms', '2026-07-21 14:26:47'),
(22, 2, 3, NULL, 'change_category', 'Fandoms → Fandom', '2026-07-21 14:27:59'),
(23, 8, 7, NULL, 'resume_group', NULL, '2026-07-21 14:30:31'),
(24, 6, 7, NULL, 'pause_group', NULL, '2026-07-31 12:13:18'),
(25, 6, 7, NULL, 'resume_group', NULL, '2026-07-31 12:13:59');

-- --------------------------------------------------------

--
-- Table structure for table `group_admins`
--

CREATE TABLE `group_admins` (
  `id` int(11) NOT NULL,
  `group_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `appointed_by` int(11) NOT NULL,
  `appointed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `group_admins`
--

INSERT INTO `group_admins` (`id`, `group_id`, `user_id`, `appointed_by`, `appointed_at`) VALUES
(2, 2, 8, 0, '2026-05-16 18:16:46'),
(3, 2, 5, 0, '2026-05-16 18:17:19'),
(4, 4, 3, 0, '2026-05-18 01:21:20'),
(5, 2, 0, 0, '2026-06-05 07:26:15'),
(6, 2, 2, 0, '2026-06-05 07:26:32'),
(7, 1, 8, 0, '2026-07-02 10:55:31'),
(8, 1, 1, 0, '2026-07-02 10:56:13'),
(9, 6, 4, 0, '2026-07-09 04:03:55'),
(10, 6, 2, 0, '2026-07-09 04:04:13'),
(11, 6, 3, 0, '2026-07-16 14:07:56');

-- --------------------------------------------------------

--
-- Table structure for table `group_bans`
--

CREATE TABLE `group_bans` (
  `id` int(10) UNSIGNED NOT NULL,
  `group_id` int(10) UNSIGNED NOT NULL,
  `banned_id` int(10) UNSIGNED NOT NULL,
  `banned_by` int(10) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `group_events`
--

CREATE TABLE `group_events` (
  `id` int(10) UNSIGNED NOT NULL,
  `group_id` int(10) UNSIGNED NOT NULL,
  `created_by` int(10) UNSIGNED NOT NULL,
  `event_name` varchar(150) NOT NULL,
  `event_image` varchar(255) DEFAULT NULL,
  `event_date` date NOT NULL,
  `event_time` time NOT NULL,
  `location_type` enum('physical','virtual') NOT NULL DEFAULT 'physical',
  `summary` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `group_events`
--

INSERT INTO `group_events` (`id`, `group_id`, `created_by`, `event_name`, `event_image`, `event_date`, `event_time`, `location_type`, `summary`, `created_at`) VALUES
(2, 4, 7, 'Girls’ Night Unfiltered', 'evt_6a59a99bce174_04099d15.jpg', '2026-07-25', '08:01:00', 'virtual', '💋 About the Event\r\n\r\nIt’s time to bring the energy, confidence, and unfiltered conversations! Join the Bad Girls Club community for an exciting night filled with fun games, spicy polls, relationship discussions, hot takes, confession moments, and plenty of laughter. Whether you’re here to make new friends, share your opinions, tell funny stories, or simply enjoy the vibe, this event is all about having a great time in a respectful and welcoming environment.\r\n\r\nExpect interactive activities, surprise challenges, and engaging conversations that will keep everyone entertained throughout the night. Dress comfortably, bring your best personality, and prepare for an unforgettable virtual girls’ night.\r\n\r\n✨ Everyone is welcome to participate, respect others, and most importantly—have fun!', '2026-07-17 04:03:39'),
(3, 1, 3, 'The Ultimate Meme Fest', 'evt_6a59ad13372d8_a1aab02e.jpg', '2026-07-18', '07:00:00', 'physical', 'Get ready for an evening packed with nonstop laughter! The Ultimate Meme Fest is a celebration of internet humor where members come together to share the funniest memes, viral videos, reaction images, GIFs, and hilarious stories from across the web. Participate in meme battles, vote for your favorite posts, compete in caption challenges, and discover new creators and trends within the community.\r\n\r\nWhether your humor is wholesome, chaotic, sarcastic, relatable, or completely random, there’s something for everyone. This event is designed to bring people together through laughter, creativity, and friendly competition. Don’t forget to bring your best memes—you might just earn the title of Meme Champion of the Night!\r\n\r\n🎉 Laugh together, share together, and make unforgettable meme-worthy memories!', '2026-07-17 04:18:27'),
(4, 6, 3, 'Prompt Like a Pro', 'evt_6a59ae0e609a4_86620e5d.jpg', '2026-07-17', '06:21:00', 'physical', 'Want to get better results from Claude AI? Join us for an interactive session focused on writing clear, effective prompts that produce more accurate, creative, and useful responses. Members will explore prompt-writing techniques, ask questions, share examples, and participate in live demonstrations showing how small changes in wording can dramatically improve AI outputs.\r\n\r\nWhether you’re using Claude for studying, coding, creative writing, research, business, or everyday tasks, this event will help you unlock more of its potential. It’s suitable for both beginners and experienced users, making it a great opportunity to learn from the community and discover new ways to work smarter with AI.\r\n\r\n💡 Learn, experiment, share ideas, and become a more confident Claude AI user.', '2026-07-17 04:22:38'),
(5, 6, 3, '🚀 CLAUDE AI POD PRESENTS', NULL, '2026-07-19', '20:23:00', 'virtual', 'About the Event\r\n\r\nThink you can push Claude AI to its limits? Join fellow community members for an exciting challenge night where participants compete to create the most impressive prompts, solve creative problems, generate unique stories, build useful tools, answer tricky questions, and complete fun AI-powered challenges.\r\n\r\nThroughout the event, members can showcase their creativity, exchange prompt ideas, and learn different techniques from one another in a relaxed and friendly environment. Expect community voting, engaging discussions, live prompt demonstrations, and plenty of opportunities to discover new ways of using Claude AI for productivity, creativity, education, and entertainment.\r\n\r\n🏆 Bring your creativity, test your prompt skills, and see what you and Claude AI can accomplish together!', '2026-07-17 04:23:51'),
(6, 6, 3, 'Ask Claude Live', 'evt_6a59aec61a472_9d5e0781.jpg', '2026-07-20', '07:25:00', 'virtual', 'About the Event\r\n\r\nJoin fellow Claude AI enthusiasts for a live community session where members ask questions, share prompts, discover new features, and exchange tips for getting the most out of Claude. Whether you’re a beginner or an experienced user, it’s a great opportunity to learn, connect, and have fun with the community.\r\n\r\n🎉 Learn something new, share your ideas, and enjoy an interactive AI discussion with fellow members.', '2026-07-17 04:25:42');

-- --------------------------------------------------------

--
-- Table structure for table `group_event_rsvps`
--

CREATE TABLE `group_event_rsvps` (
  `id` int(10) UNSIGNED NOT NULL,
  `event_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `rsvp_type` enum('interested','going') NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `group_event_rsvps`
--

INSERT INTO `group_event_rsvps` (`id`, `event_id`, `user_id`, `rsvp_type`, `created_at`) VALUES
(2, 2, 7, 'going', '2026-07-17 04:05:24'),
(3, 2, 7, 'interested', '2026-07-17 04:05:27'),
(4, 2, 3, 'going', '2026-07-17 04:05:52'),
(5, 2, 3, 'interested', '2026-07-17 04:05:58'),
(6, 3, 3, 'going', '2026-07-17 04:18:35'),
(7, 4, 3, 'going', '2026-07-17 04:22:47'),
(8, 6, 3, 'interested', '2026-07-17 04:26:13'),
(9, 5, 7, 'interested', '2026-07-17 04:27:03'),
(10, 4, 7, 'interested', '2026-07-17 04:27:07'),
(12, 5, 7, 'going', '2026-07-17 04:27:14'),
(13, 6, 7, 'going', '2026-07-17 07:00:12'),
(14, 6, 7, 'interested', '2026-07-17 07:00:22'),
(15, 6, 3, 'going', '2026-07-18 08:57:41');

-- --------------------------------------------------------

--
-- Table structure for table `group_hashtags`
--

CREATE TABLE `group_hashtags` (
  `id` int(11) NOT NULL,
  `group_id` int(11) NOT NULL,
  `tag` varchar(50) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `group_hashtags`
--

INSERT INTO `group_hashtags` (`id`, `group_id`, `tag`, `created_at`) VALUES
(1, 6, 'claudeai', '2026-07-09 05:42:30'),
(2, 6, 'claudememes', '2026-07-09 05:43:02'),
(3, 6, 'aicomedy', '2026-07-09 05:43:58'),
(4, 6, 'claudebeingclaude', '2026-07-09 05:44:22'),
(5, 6, 'promptfails', '2026-07-09 05:44:58'),
(6, 6, 'promptengineering', '2026-07-09 05:45:20'),
(7, 2, 'cardibmemes', '2026-07-09 05:49:39'),
(8, 2, 'bardigang', '2026-07-09 05:51:50'),
(9, 6, 'memesofclaude', '2026-07-17 06:58:49');

-- --------------------------------------------------------

--
-- Table structure for table `group_highlights`
--

CREATE TABLE `group_highlights` (
  `id` int(10) UNSIGNED NOT NULL,
  `group_id` int(10) UNSIGNED NOT NULL,
  `posted_by` int(10) UNSIGNED NOT NULL,
  `type` enum('announcement','poll','milestone','top_meme','mod_post','event','rules','giveaway','meme','faq') NOT NULL DEFAULT 'announcement',
  `title` varchar(300) DEFAULT NULL,
  `meme_id` int(10) UNSIGNED DEFAULT NULL,
  `caption` text DEFAULT NULL,
  `poll_question` varchar(300) DEFAULT NULL,
  `faq_question` varchar(300) DEFAULT NULL,
  `faq_answer` text DEFAULT NULL,
  `is_pinned` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `event_date` datetime DEFAULT NULL,
  `event_location` varchar(255) DEFAULT NULL,
  `extra_value` varchar(150) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `group_highlights`
--

INSERT INTO `group_highlights` (`id`, `group_id`, `posted_by`, `type`, `title`, `meme_id`, `caption`, `poll_question`, `faq_question`, `faq_answer`, `is_pinned`, `created_at`, `event_date`, `event_location`, `extra_value`) VALUES
(1, 1, 3, 'poll', NULL, NULL, NULL, 'Do You have an all time favorite meme', NULL, NULL, 0, '2026-06-28 04:17:54', NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `group_highlight_likes`
--

CREATE TABLE `group_highlight_likes` (
  `id` int(10) UNSIGNED NOT NULL,
  `highlight_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `group_highlight_likes`
--

INSERT INTO `group_highlight_likes` (`id`, `highlight_id`, `user_id`) VALUES
(2, 1, 1),
(3, 1, 2);

-- --------------------------------------------------------

--
-- Table structure for table `group_highlight_poll_options`
--

CREATE TABLE `group_highlight_poll_options` (
  `id` int(10) UNSIGNED NOT NULL,
  `highlight_id` int(10) UNSIGNED NOT NULL,
  `option_text` varchar(200) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `group_highlight_poll_options`
--

INSERT INTO `group_highlight_poll_options` (`id`, `highlight_id`, `option_text`) VALUES
(1, 1, 'Yes!!!! Absolutely'),
(2, 1, 'Yes but its changes every now and then'),
(3, 1, 'No not really');

-- --------------------------------------------------------

--
-- Table structure for table `group_highlight_poll_votes`
--

CREATE TABLE `group_highlight_poll_votes` (
  `id` int(10) UNSIGNED NOT NULL,
  `highlight_id` int(10) UNSIGNED NOT NULL,
  `option_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `group_highlight_poll_votes`
--

INSERT INTO `group_highlight_poll_votes` (`id`, `highlight_id`, `option_id`, `user_id`) VALUES
(1, 1, 2, 3),
(2, 1, 1, 7),
(3, 1, 1, 1),
(4, 1, 1, 2);

-- --------------------------------------------------------

--
-- Table structure for table `group_join_requests`
--

CREATE TABLE `group_join_requests` (
  `id` int(10) UNSIGNED NOT NULL,
  `group_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `status` enum('pending','approved','denied') DEFAULT 'pending',
  `requested_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `decided_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `group_join_requests`
--

INSERT INTO `group_join_requests` (`id`, `group_id`, `user_id`, `status`, `requested_at`, `decided_at`) VALUES
(1, 6, 4, 'approved', '2026-07-09 04:01:24', '2026-07-09 04:01:53'),
(3, 6, 16, 'approved', '2026-07-12 06:04:54', '2026-07-12 06:06:43'),
(4, 6, 3, 'approved', '2026-07-16 11:57:28', '2026-07-16 12:00:09'),
(5, 1, 7, 'approved', '2026-07-17 10:50:46', '2026-07-17 10:51:03'),
(7, 8, 3, 'approved', '2026-07-21 14:15:20', '2026-07-21 14:15:52');

-- --------------------------------------------------------

--
-- Table structure for table `group_members`
--

CREATE TABLE `group_members` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `group_id` int(10) UNSIGNED NOT NULL,
  `joined_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `group_members`
--

INSERT INTO `group_members` (`id`, `user_id`, `group_id`, `joined_at`) VALUES
(9, 5, 1, '2026-03-22 11:06:17'),
(67, 3, 2, '2026-04-02 07:35:36'),
(73, 5, 2, '2026-04-06 00:53:46'),
(77, 4, 2, '2026-04-09 11:53:25'),
(78, 2, 2, '2026-04-20 05:52:12'),
(80, 8, 2, '2026-04-20 16:32:26'),
(81, 8, 4, '2026-04-20 18:33:52'),
(84, 1, 2, '2026-04-23 07:48:41'),
(85, 1, 4, '2026-05-05 11:04:42'),
(101, 4, 4, '2026-06-10 13:21:04'),
(106, 2, 4, '2026-06-12 10:20:58'),
(114, 1, 1, '2026-06-13 10:32:10'),
(116, 8, 1, '2026-06-15 07:55:34'),
(118, 16, 1, '2026-06-16 10:18:16'),
(120, 7, 4, '2026-06-23 02:48:14'),
(122, 3, 4, '2026-06-24 08:17:05'),
(123, 7, 6, '2026-06-27 17:30:18'),
(127, 8, 6, '2026-06-28 08:50:23'),
(130, 2, 6, '2026-07-01 11:54:04'),
(131, 13, 6, '2026-07-01 11:54:59'),
(133, 1, 6, '2026-07-02 13:53:24'),
(139, 3, 1, '2026-07-08 09:11:17'),
(142, 4, 1, '2026-07-09 04:01:09'),
(143, 4, 6, '2026-07-09 04:01:53'),
(144, 16, 6, '2026-07-12 06:06:43'),
(145, 2, 1, '2026-07-14 05:42:59'),
(147, 16, 4, '2026-07-15 17:13:46'),
(154, 16, 2, '2026-07-16 05:54:25'),
(155, 3, 6, '2026-07-16 12:00:09'),
(159, 7, 1, '2026-07-17 10:52:13'),
(160, 7, 7, '2026-07-21 13:32:09'),
(161, 7, 8, '2026-07-21 13:44:26'),
(162, 3, 7, '2026-07-21 14:13:21'),
(163, 3, 8, '2026-07-21 14:15:52'),
(164, 7, 2, '2026-07-22 10:44:51');

-- --------------------------------------------------------

--
-- Table structure for table `group_name_history`
--

CREATE TABLE `group_name_history` (
  `id` int(11) NOT NULL,
  `group_id` int(10) UNSIGNED NOT NULL,
  `old_title` varchar(100) NOT NULL,
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `group_name_history`
--

INSERT INTO `group_name_history` (`id`, `group_id`, `old_title`, `changed_at`) VALUES
(1, 1, 'Funny memes ', '2026-07-03 15:42:00'),
(2, 1, 'Funny Club', '2026-07-03 15:42:34'),
(3, 2, 'Cardi Fan page', '2026-07-03 17:53:53'),
(4, 6, 'Claude', '2026-07-21 10:16:35'),
(5, 6, 'Claudeeeeeeeeeeeeeeeeeeeeee', '2026-07-21 10:17:30'),
(6, 6, 'Claude', '2026-07-21 10:22:51'),
(7, 6, 'Claudeeeeeeeeeee', '2026-07-21 10:24:38'),
(8, 6, 'Claude', '2026-07-21 10:33:19'),
(9, 6, 'Claudeeeeeeeeeeeeee', '2026-07-21 10:34:17'),
(10, 6, 'Claudeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee', '2026-07-21 10:35:24');

-- --------------------------------------------------------

--
-- Table structure for table `group_notif_off`
--

CREATE TABLE `group_notif_off` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `group_id` int(10) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `group_pins`
--

CREATE TABLE `group_pins` (
  `user_id` int(10) UNSIGNED NOT NULL,
  `group_id` int(10) UNSIGNED NOT NULL,
  `sort_order` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `group_pins`
--

INSERT INTO `group_pins` (`user_id`, `group_id`, `sort_order`, `created_at`) VALUES
(3, 2, 0, '2026-06-16 11:23:38'),
(16, 1, 0, '2026-07-15 14:12:04');

-- --------------------------------------------------------

--
-- Table structure for table `group_posts`
--

CREATE TABLE `group_posts` (
  `id` int(10) UNSIGNED NOT NULL,
  `group_id` int(10) UNSIGNED NOT NULL,
  `posted_by` int(10) UNSIGNED NOT NULL,
  `meme_id` int(10) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `group_posts`
--

INSERT INTO `group_posts` (`id`, `group_id`, `posted_by`, `meme_id`, `created_at`) VALUES
(2, 1, 4, 6, '2026-03-21 09:29:38'),
(3, 1, 4, 7, '2026-03-21 09:32:04'),
(7, 2, 3, 11, '2026-03-21 13:52:09'),
(13, 2, 1, 54, '2026-03-28 07:43:32'),
(26, 1, 2, 116, '2026-07-14 04:29:26'),
(28, 1, 7, 119, '2026-07-19 08:28:58'),
(29, 1, 7, 120, '2026-07-19 08:42:45'),
(32, 7, 3, 137, '2026-07-29 15:15:17'),
(36, 1, 7, 144, '2026-07-30 08:10:55');

-- --------------------------------------------------------

--
-- Table structure for table `group_post_reports`
--

CREATE TABLE `group_post_reports` (
  `id` int(11) NOT NULL,
  `group_id` int(11) NOT NULL,
  `group_post_id` int(11) NOT NULL,
  `meme_id` int(11) NOT NULL,
  `reported_by` int(11) NOT NULL,
  `reported_user_id` int(11) NOT NULL,
  `reason` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('pending','resolved','dismissed') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `group_post_reports`
--

INSERT INTO `group_post_reports` (`id`, `group_id`, `group_post_id`, `meme_id`, `reported_by`, `reported_user_id`, `reason`, `description`, `status`, `created_at`) VALUES
(1, 2, 7, 11, 16, 3, 'Hate Speech or Discrimination', '', 'dismissed', '2026-07-15 18:32:11'),
(2, 1, 3, 7, 16, 4, 'Other', 'Cause im a bad bitch', 'dismissed', '2026-07-15 19:31:09'),
(3, 2, 13, 54, 16, 1, 'Hate Speech or Discrimination', '', 'dismissed', '2026-07-16 11:50:34'),
(4, 1, 3, 7, 7, 4, 'Hate Speech or Discrimination', '', 'dismissed', '2026-07-17 07:04:19'),
(5, 1, 2, 6, 7, 4, 'Off-Topic / Doesn\'t Fit This Pod', '', 'dismissed', '2026-07-17 07:06:13');

-- --------------------------------------------------------

--
-- Table structure for table `group_reactions`
--

CREATE TABLE `group_reactions` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `group_post_id` int(10) UNSIGNED NOT NULL,
  `reaction` enum('?','?','?') NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `group_search_recents`
--

CREATE TABLE `group_search_recents` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `group_id` int(10) UNSIGNED NOT NULL,
  `item_type` varchar(20) NOT NULL,
  `item_id` varchar(64) NOT NULL,
  `item_data` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `group_search_recents`
--

INSERT INTO `group_search_recents` (`id`, `user_id`, `group_id`, `item_type`, `item_id`, `item_data`, `created_at`) VALUES
(2, 3, 1, 'member', '8', '{\"type\":\"member\",\"id\":8,\"username\":\"Chelsey\",\"nickname\":\"Pretty Barbie\",\"profile_pic\":\"uploads\\/1776693705_Chelsey.jpeg\"}', '2026-06-27 07:34:30'),
(4, 3, 2, 'member', '2', '{\"type\":\"member\",\"id\":2,\"username\":\"Dani\",\"nickname\":\"Starboy\",\"profile_pic\":\"uploads\\/1774069944_Dani.jpg\"}', '2026-07-08 07:44:56');

-- --------------------------------------------------------

--
-- Table structure for table `group_views`
--

CREATE TABLE `group_views` (
  `id` int(10) UNSIGNED NOT NULL,
  `viewer_id` int(10) UNSIGNED NOT NULL,
  `group_id` int(10) UNSIGNED NOT NULL,
  `viewed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `group_views`
--

INSERT INTO `group_views` (`id`, `viewer_id`, `group_id`, `viewed_at`) VALUES
(1, 3, 4, '2026-07-01 11:33:54'),
(2, 3, 2, '2026-07-01 11:34:40'),
(3, 2, 4, '2026-07-01 11:50:48'),
(4, 2, 6, '2026-07-01 11:53:54'),
(5, 2, 1, '2026-07-01 11:54:30'),
(6, 13, 6, '2026-07-01 11:54:56'),
(7, 13, 1, '2026-07-01 16:24:09'),
(8, 2, 2, '2026-07-01 17:05:52'),
(9, 8, 2, '2026-07-01 17:29:36'),
(10, 8, 4, '2026-07-01 17:52:02'),
(11, 8, 6, '2026-07-01 18:20:29'),
(12, 8, 1, '2026-07-01 19:17:53'),
(13, 8, 2, '2026-07-02 05:16:22'),
(14, 8, 1, '2026-07-02 05:16:43'),
(15, 8, 6, '2026-07-02 05:20:52'),
(16, 8, 4, '2026-07-02 05:28:23'),
(17, 1, 4, '2026-07-02 10:28:40'),
(18, 1, 2, '2026-07-02 10:29:54'),
(19, 1, 1, '2026-07-02 10:30:53'),
(20, 3, 1, '2026-07-02 10:55:01'),
(21, 1, 6, '2026-07-02 11:57:13'),
(22, 1, 6, '2026-07-03 01:50:37'),
(23, 1, 4, '2026-07-03 01:57:06'),
(24, 1, 1, '2026-07-03 02:10:04'),
(25, 3, 1, '2026-07-03 15:41:40'),
(26, 3, 2, '2026-07-03 15:44:18'),
(27, 3, 4, '2026-07-03 15:49:05'),
(28, 3, 6, '2026-07-03 16:31:45'),
(29, 7, 2, '2026-07-03 17:59:48'),
(30, 7, 6, '2026-07-03 18:00:12'),
(31, 7, 1, '2026-07-03 18:01:04'),
(32, 7, 4, '2026-07-03 19:18:51'),
(33, 7, 4, '2026-07-05 06:11:11'),
(34, 7, 1, '2026-07-05 06:15:52'),
(35, 2, 2, '2026-07-05 13:56:57'),
(36, 3, 1, '2026-07-07 07:09:37'),
(37, 3, 4, '2026-07-07 08:46:32'),
(38, 3, 2, '2026-07-07 18:29:05'),
(39, 3, 6, '2026-07-07 19:34:43'),
(40, 3, 6, '2026-07-08 03:34:45'),
(41, 3, 2, '2026-07-08 07:26:24'),
(42, 3, 4, '2026-07-08 08:10:23'),
(43, 3, 1, '2026-07-08 08:48:03'),
(44, 7, 6, '2026-07-09 03:37:05'),
(45, 3, 6, '2026-07-09 03:37:26'),
(46, 4, 6, '2026-07-09 03:38:16'),
(47, 4, 1, '2026-07-09 03:57:05'),
(48, 4, 2, '2026-07-09 05:05:00'),
(49, 3, 2, '2026-07-09 05:48:50'),
(50, 3, 4, '2026-07-09 05:59:37'),
(51, 7, 2, '2026-07-09 15:25:38'),
(52, 7, 4, '2026-07-09 15:59:32'),
(53, 7, 1, '2026-07-09 16:13:13'),
(54, 3, 1, '2026-07-11 10:21:09'),
(55, 3, 6, '2026-07-11 10:22:44'),
(56, 3, 2, '2026-07-11 10:29:52'),
(57, 3, 2, '2026-07-12 05:50:38'),
(58, 3, 6, '2026-07-12 05:58:52'),
(59, 3, 4, '2026-07-12 06:00:11'),
(60, 16, 6, '2026-07-12 06:04:24'),
(61, 7, 6, '2026-07-12 06:06:11'),
(62, 16, 6, '2026-07-13 04:11:52'),
(63, 16, 1, '2026-07-13 04:12:14'),
(64, 16, 2, '2026-07-13 04:12:42'),
(65, 2, 2, '2026-07-13 08:15:33'),
(66, 7, 6, '2026-07-13 09:13:05'),
(67, 7, 2, '2026-07-13 09:13:23'),
(68, 2, 6, '2026-07-13 10:17:53'),
(69, 2, 4, '2026-07-13 12:07:53'),
(70, 2, 1, '2026-07-13 12:33:31'),
(71, 4, 1, '2026-07-13 12:34:29'),
(72, 2, 1, '2026-07-14 04:25:39'),
(73, 2, 4, '2026-07-14 04:32:24'),
(74, 2, 2, '2026-07-14 04:32:40'),
(75, 2, 6, '2026-07-14 06:51:28'),
(76, 2, 1, '2026-07-15 05:39:03'),
(77, 2, 2, '2026-07-15 05:40:36'),
(78, 16, 6, '2026-07-15 10:57:30'),
(79, 16, 1, '2026-07-15 10:57:39'),
(80, 3, 4, '2026-07-15 11:28:42'),
(81, 3, 6, '2026-07-15 11:28:46'),
(82, 3, 2, '2026-07-15 11:28:56'),
(83, 3, 1, '2026-07-15 11:29:08'),
(84, 16, 2, '2026-07-15 13:36:53'),
(85, 16, 4, '2026-07-15 17:12:55'),
(86, 16, 2, '2026-07-16 05:52:49'),
(87, 16, 1, '2026-07-16 05:56:31'),
(88, 3, 1, '2026-07-16 06:52:12'),
(89, 4, 6, '2026-07-16 07:33:34'),
(90, 16, 6, '2026-07-16 09:42:01'),
(91, 3, 2, '2026-07-16 09:43:16'),
(92, 3, 4, '2026-07-16 09:43:21'),
(93, 3, 6, '2026-07-16 09:46:12'),
(94, 7, 1, '2026-07-16 11:56:45'),
(95, 7, 6, '2026-07-16 11:56:52'),
(96, 7, 2, '2026-07-16 12:30:19'),
(97, 3, 6, '2026-07-17 03:19:39'),
(98, 7, 2, '2026-07-17 03:22:12'),
(99, 7, 4, '2026-07-17 03:31:23'),
(100, 3, 4, '2026-07-17 04:05:42'),
(101, 3, 2, '2026-07-17 04:08:31'),
(102, 3, 1, '2026-07-17 04:08:37'),
(103, 7, 6, '2026-07-17 04:26:53'),
(104, 7, 1, '2026-07-17 07:03:58'),
(105, 3, 6, '2026-07-18 03:52:10'),
(106, 7, 4, '2026-07-18 03:55:20'),
(107, 3, 4, '2026-07-18 03:55:50'),
(108, 7, 1, '2026-07-18 04:34:52'),
(109, 3, 1, '2026-07-18 05:47:59'),
(110, 7, 1, '2026-07-19 04:37:05'),
(111, 7, 2, '2026-07-19 05:30:51'),
(112, 7, 6, '2026-07-19 05:43:30'),
(113, 7, 1, '2026-07-20 07:20:52'),
(114, 3, 1, '2026-07-21 06:54:37'),
(115, 7, 1, '2026-07-21 08:00:37'),
(116, 7, 2, '2026-07-21 08:07:06'),
(117, 7, 6, '2026-07-21 08:35:50'),
(118, 7, 4, '2026-07-21 08:36:15'),
(119, 3, 2, '2026-07-21 09:04:45'),
(120, 7, 7, '2026-07-21 13:32:09'),
(121, 7, 8, '2026-07-21 13:44:26'),
(122, 3, 7, '2026-07-21 14:13:18'),
(123, 3, 4, '2026-07-21 14:14:07'),
(124, 3, 8, '2026-07-21 14:15:00'),
(125, 7, 8, '2026-07-22 06:26:15'),
(126, 7, 1, '2026-07-22 07:08:36'),
(127, 7, 6, '2026-07-22 07:43:03'),
(128, 7, 4, '2026-07-22 07:43:11'),
(129, 7, 2, '2026-07-22 07:44:57'),
(130, 7, 7, '2026-07-22 09:00:20'),
(131, 7, 1, '2026-07-23 05:44:03'),
(132, 7, 1, '2026-07-24 10:00:48'),
(133, 7, 6, '2026-07-24 10:31:54'),
(134, 7, 4, '2026-07-24 10:33:31'),
(135, 7, 2, '2026-07-24 10:33:35'),
(136, 7, 7, '2026-07-24 10:34:18'),
(137, 7, 7, '2026-07-25 08:52:49'),
(138, 19, 1, '2026-07-25 14:24:02'),
(139, 7, 7, '2026-07-26 15:48:56'),
(140, 7, 1, '2026-07-26 15:53:11'),
(141, 7, 1, '2026-07-27 05:00:41'),
(142, 3, 7, '2026-07-29 15:12:03'),
(143, 7, 7, '2026-07-29 15:17:51'),
(144, 7, 1, '2026-07-30 07:00:54'),
(145, 7, 2, '2026-07-31 11:59:01'),
(146, 7, 1, '2026-07-31 12:07:56'),
(147, 7, 6, '2026-07-31 12:12:36'),
(148, 7, 2, '2026-08-01 07:10:14');

-- --------------------------------------------------------

--
-- Table structure for table `highlights`
--

CREATE TABLE `highlights` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `title` varchar(255) NOT NULL,
  `cover_image` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `likes`
--

CREATE TABLE `likes` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `meme_id` int(10) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `likes`
--

INSERT INTO `likes` (`id`, `user_id`, `meme_id`, `created_at`) VALUES
(3, 2, 1, '2026-03-21 05:18:59'),
(6, 4, 2, '2026-03-21 09:00:06'),
(7, 4, 1, '2026-03-21 09:00:12'),
(9, 3, 6, '2026-03-21 09:34:17'),
(24, 1, 16, '2026-03-22 09:32:09'),
(27, 1, 18, '2026-03-22 09:48:09'),
(29, 4, 16, '2026-03-22 09:52:56'),
(33, 5, 19, '2026-03-22 11:42:47'),
(34, 5, 11, '2026-03-22 11:43:45'),
(37, 3, 16, '2026-03-22 14:52:56'),
(40, 2, 6, '2026-03-23 06:55:11'),
(50, 1, 6, '2026-03-23 18:45:45'),
(52, 5, 3, '2026-03-24 10:36:52'),
(53, 5, 31, '2026-03-24 10:37:28'),
(55, 4, 38, '2026-03-25 06:10:45'),
(58, 2, 40, '2026-03-25 13:01:08'),
(59, 2, 39, '2026-03-25 13:01:13'),
(71, 3, 38, '2026-03-26 10:17:53'),
(72, 3, 39, '2026-03-26 10:23:11'),
(73, 2, 38, '2026-03-26 10:31:10'),
(74, 2, 7, '2026-03-26 10:32:27'),
(75, 2, 3, '2026-03-26 10:32:29'),
(76, 2, 48, '2026-03-27 04:07:28'),
(82, 4, 3, '2026-03-28 18:33:51'),
(84, 4, 31, '2026-03-28 19:10:57'),
(86, 4, 19, '2026-03-28 19:41:55'),
(87, 4, 54, '2026-03-28 19:42:01'),
(88, 4, 11, '2026-03-28 19:42:04'),
(89, 4, 7, '2026-03-29 05:56:05'),
(93, 4, 48, '2026-03-30 10:37:49'),
(94, 3, 40, '2026-04-02 11:03:25'),
(97, 1, 31, '2026-04-06 15:29:57'),
(101, 1, 19, '2026-04-06 16:43:34'),
(106, 1, 54, '2026-04-09 04:10:32'),
(107, 7, 1, '2026-04-09 04:53:02'),
(109, 7, 11, '2026-04-09 09:59:07'),
(121, 7, 2, '2026-04-09 10:05:59'),
(126, 7, 54, '2026-04-18 07:34:49'),
(130, 3, 66, '2026-04-18 09:46:13'),
(132, 3, 31, '2026-04-19 02:53:07'),
(135, 3, 19, '2026-04-19 03:45:38'),
(137, 3, 2, '2026-04-19 03:55:28'),
(143, 3, 7, '2026-04-19 04:15:53'),
(145, 3, 48, '2026-04-19 17:06:13'),
(146, 3, 18, '2026-04-19 17:06:37'),
(148, 2, 16, '2026-04-19 17:20:42'),
(151, 2, 66, '2026-04-20 04:38:24'),
(152, 2, 31, '2026-04-20 04:41:48'),
(154, 2, 19, '2026-04-20 04:54:20'),
(155, 2, 11, '2026-04-20 05:20:14'),
(157, 2, 18, '2026-04-20 06:17:24'),
(158, 7, 31, '2026-04-20 07:29:05'),
(159, 7, 48, '2026-04-20 07:29:19'),
(161, 7, 16, '2026-04-20 07:30:12'),
(162, 7, 40, '2026-04-20 07:30:20'),
(164, 8, 6, '2026-04-20 17:22:20'),
(167, 8, 11, '2026-04-20 17:34:33'),
(168, 8, 48, '2026-04-20 18:36:40'),
(169, 8, 18, '2026-04-20 18:56:23'),
(170, 8, 1, '2026-04-20 18:56:26'),
(171, 8, 66, '2026-04-20 18:56:34'),
(172, 8, 3, '2026-04-20 18:56:44'),
(173, 8, 7, '2026-04-20 18:56:46'),
(174, 8, 19, '2026-04-20 18:59:17'),
(175, 8, 67, '2026-04-20 19:00:42'),
(176, 3, 68, '2026-04-20 19:03:18'),
(177, 3, 67, '2026-04-20 19:03:26'),
(178, 3, 54, '2026-04-20 19:10:22'),
(179, 3, 11, '2026-04-21 05:03:38'),
(180, 7, 67, '2026-04-21 05:08:46'),
(181, 7, 68, '2026-04-21 17:25:21'),
(182, 7, 19, '2026-04-23 05:24:36'),
(183, 1, 68, '2026-04-23 05:29:42'),
(184, 1, 67, '2026-04-23 05:29:44'),
(185, 1, 7, '2026-04-23 05:29:52'),
(186, 1, 66, '2026-04-23 06:26:40'),
(188, 1, 11, '2026-04-23 07:38:47'),
(189, 1, 2, '2026-04-23 07:40:41'),
(194, 2, 67, '2026-05-05 05:44:41'),
(195, 7, 66, '2026-05-05 14:11:27'),
(196, 7, 3, '2026-05-05 16:18:37'),
(203, 1, 39, '2026-05-13 01:11:00'),
(205, 1, 85, '2026-05-13 01:22:45'),
(212, 1, 38, '2026-05-20 11:30:49'),
(213, 4, 85, '2026-05-22 07:48:22'),
(215, 1, 1, '2026-05-24 04:27:38'),
(216, 3, 3, '2026-06-13 08:54:51'),
(217, 3, 1, '2026-06-13 08:55:15'),
(218, 16, 66, '2026-07-15 08:39:39'),
(219, 16, 40, '2026-07-15 08:39:41'),
(220, 16, 1, '2026-07-15 08:39:45'),
(221, 16, 18, '2026-07-15 08:39:48'),
(222, 16, 39, '2026-07-15 08:40:20'),
(223, 7, 38, '2026-07-18 12:07:55'),
(224, 7, 6, '2026-07-18 15:09:16'),
(225, 7, 39, '2026-07-19 05:54:14'),
(229, 13, 119, '2026-07-19 08:39:04'),
(230, 2, 119, '2026-07-19 08:44:09'),
(231, 2, 120, '2026-07-19 08:44:38'),
(232, 13, 120, '2026-07-19 08:45:15'),
(233, 5, 120, '2026-07-19 08:48:44'),
(234, 5, 119, '2026-07-19 08:48:47'),
(235, 16, 120, '2026-07-19 08:52:21'),
(236, 16, 119, '2026-07-19 08:52:24'),
(256, 7, 18, '2026-07-22 18:42:49'),
(260, 7, 116, '2026-07-22 19:06:17'),
(265, 7, 7, '2026-07-22 19:39:16'),
(283, 7, 124, '2026-07-24 08:48:30'),
(291, 7, 140, '2026-07-29 19:34:43'),
(295, 7, 147, '2026-08-01 08:30:02');

-- --------------------------------------------------------

--
-- Table structure for table `linked_accounts`
--

CREATE TABLE `linked_accounts` (
  `primary_user_id` int(11) NOT NULL,
  `linked_user_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `linked_accounts`
--

INSERT INTO `linked_accounts` (`primary_user_id`, `linked_user_id`) VALUES
(2, 13),
(7, 19),
(13, 2),
(19, 7);

-- --------------------------------------------------------

--
-- Table structure for table `links`
--

CREATE TABLE `links` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `title` varchar(100) NOT NULL,
  `url` varchar(255) NOT NULL,
  `icon` varchar(50) DEFAULT 'ri-link-line',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `links`
--

INSERT INTO `links` (`id`, `user_id`, `title`, `url`, `icon`, `created_at`) VALUES
(5, 1, 'my other page', 'http://localhost/Peura/profile.php?user_id=4', 'ri-instagram-line', '2026-04-06 16:17:48'),
(6, 1, 'ig handle', 'https://www.instagram.com/?hl=en', 'ri-linkedin-line', '2026-04-06 16:18:44'),
(7, 3, 'Facebook account', 'https://web.facebook.com/profile.php?id=61573431729328', 'ri-facebook-line', '2026-06-20 15:05:39'),
(8, 3, 'Linkedin', 'https://en.wikipedia.org/wiki/Nicki_Minaj', 'ri-linkedin-line', '2026-06-20 16:27:42'),
(9, 3, 'milla', 'https://www.provemyage.com/', 'ri-link-line', '2026-07-31 10:34:35');

-- --------------------------------------------------------

--
-- Table structure for table `memes`
--

CREATE TABLE `memes` (
  `id` int(10) UNSIGNED NOT NULL,
  `uploaded_by` int(10) UNSIGNED NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `caption` text DEFAULT NULL,
  `type` enum('image','video','text') DEFAULT 'image',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `remix_of` int(10) UNSIGNED DEFAULT NULL,
  `views` int(10) UNSIGNED DEFAULT 0,
  `category` varchar(50) DEFAULT NULL,
  `user_view_score` float DEFAULT 0,
  `ai_score` float DEFAULT 0,
  `trend_velocity` float DEFAULT 0,
  `is_adult` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `memes`
--

INSERT INTO `memes` (`id`, `uploaded_by`, `file_name`, `caption`, `type`, `created_at`, `remix_of`, `views`, `category`, `user_view_score`, `ai_score`, `trend_velocity`, `is_adult`) VALUES
(1, 1, '1774069791_3918.jpg', 'How i be looking when i just did the most horrible thing :)', 'image', '2026-03-21 05:09:51', NULL, 0, NULL, 0, 0.00134059, 0.0378732, 0),
(2, 2, '1774070108_1587.mp4', '#Me and the family on a road trip', 'video', '2026-03-21 05:15:08', NULL, 0, NULL, 0, 0.000536324, 0.015151, 0),
(3, 3, '1774070932_5579.jpg', 'when sickness lie finally catches up with you :) lol', 'image', '2026-03-21 05:28:52', NULL, 0, NULL, 0, 0.000983686, 0.0277848, 0),
(6, 4, '1774085378_photo_2026-03-21_10-27-01.jpg', 'me and my boring life in bed but im at peace :)', 'image', '2026-03-21 09:29:38', NULL, 0, NULL, 0, 0.00117141, 0.0330039, 0),
(7, 4, '1774085524_photo_2026-03-21_10-26-51.jpg', 'how begining of the weekend always got me feeling', 'image', '2026-03-21 09:32:04', NULL, 0, NULL, 0, 0.0014869, 0.0209459, 0),
(11, 3, '1774101129_video.mp4', 'when they deny evidence but its literally in their face', 'video', '2026-03-21 13:52:09', NULL, 0, NULL, 0, 0.00213527, 0.00187481, 0),
(16, 2, '0', 'I hate this school lol', 'text', '2026-03-22 08:29:20', NULL, 0, NULL, 0, 0.00070644, 0.0196132, 0),
(18, 1, '0', 'If you ever need nothing im here for you', 'text', '2026-03-22 09:34:31', NULL, 0, NULL, 0, 0.000566355, 0.0157129, 0),
(19, 1, '0', 'Mentally rn, I\'m the chicken from moana', 'text', '2026-03-22 09:35:23', NULL, 0, NULL, 0, 0.0019351, 0.0536866, 0),
(31, 1, '1774289901_2116.mp4', 'My Face when I see Fine Shyt', 'video', '2026-03-23 18:18:21', NULL, 0, NULL, 0, 0.00120942, 0.0328329, 0),
(38, 3, '1774418756_6623.jpg', 'when you the only serious one in the group', 'image', '2026-03-25 06:05:56', NULL, 0, NULL, 0, 0.00103213, 0.027331, 0),
(39, 1, '1774418756_6623.jpg', '↩️ lol This is too real @Leah', 'image', '2026-03-25 06:07:15', 38, 0, NULL, 0, 0.000651903, 0.0172622, 0),
(40, 4, '1774419027_6037.jpg', 'My Face when something is suspicious', 'image', '2026-03-25 06:10:27', NULL, 0, NULL, 0, 0.00146695, 0.038843, 0),
(48, 4, 'Text.jpg', '[AD] [AD] Why do i feel so happy today lol ', 'text', '2026-03-25 09:38:57', NULL, 0, NULL, 0, 0.00164221, 0.0433757, 0),
(54, 1, '1774683812_photo_2026-03-21_10-26-31.jpg', 'lol', 'image', '2026-03-28 07:43:32', NULL, 0, NULL, 0, 0.00205512, 0.000402217, 0),
(66, 3, '1776502286_7229.jpg', ' My face when i hear rubbish 🤣🤣 #funny', 'image', '2026-04-18 08:51:26', NULL, 0, NULL, 0, 0.0170713, 0.0944825, 0),
(67, 8, '0', 'The World when is my turn to be an adult', 'text', '2026-04-20 19:00:34', NULL, 0, NULL, 0, 0.0534288, 0.429028, 0),
(68, 8, '0', 'Im literally a Baddie if you dont agree boohoo', 'text', '2026-04-20 19:02:25', NULL, 0, NULL, 0, 0.0256655, 0.206043, 0),
(85, 1, '', 'What a funny year this week has been #Funny #memes #love', 'text', '2026-04-23 08:38:59', NULL, 0, NULL, 0, 0, 0, 0),
(100, 4, 'Text.jpg', ' if youe see a toilet in your dream ,dont use it @Dani #relatable  ', 'text', '2026-05-22 08:42:34', NULL, 0, NULL, 0, 0, 0, 0),
(116, 2, '1784003366_6a55bb263fe71.gif', 'when im trying to thing of that one thing that means me getting a Pass or Fail in the exam #Funny #Cynthia #Funny Memes', 'image', '2026-07-14 04:29:26', NULL, 0, NULL, 0, 0, 0, 0),
(119, 7, '1784449738_6a5c8aca3a093.jpg', 'POV: The barber said, “Trust me bro, I know a style that’ll make your forehead look smaller.” 💀  Bro walked out looking like his brain got the premium penthouse suite. 😭 That forehead got enough space to host the Olympics, build an airport, open a shopping mall, and still have room left for affordable housing. NASA just called asking if they can use it as a secondary landing strip. Every time he gets an idea, it has to travel three business days before reaching the back of his head. Even his hairline packed its bags and said, “I’m moving to the suburbs.” 😭😂  #Meme #Funny #Forehead #BigBrainEnergy #BarberFail #HairlineOnVacation #ComedyGold #LaughOutLoud #ViralMemes #Mood #NoOffense #JustJokes #InternetHumor #LOL #Dezzme', 'image', '2026-07-19 08:28:58', NULL, 0, NULL, 0, 0, 0, 0),
(120, 7, '1784450565_6a5c8e052e5c8.mp4', 'POV: She said, “Take my picture!” 📸✨  Little miss made sure every pose was approved by the Princess Council. 😂 One hand on the hip, biggest smile activated, and confidence turned all the way up. She didn’t come to play—she came to steal hearts, collect compliments, and remind everyone that being adorable is a full-time job. Someone hand this tiny superstar her crown already! 👑💖  #LittlePrincess #CutiePie #TooCute #FutureModel #TinyButMighty #PicturePerfect #SweetMoments #ChildhoodMagic #SmileOfTheDay #Adorable #LittleStar #PreciousMoments #HappyVibes #CuteKids #Dezzme', 'video', '2026-07-19 08:42:45', NULL, 0, NULL, 0, 0, 0, 0),
(124, 7, '1784547716_6307.mp4', ' #memes', 'video', '2026-07-20 11:41:56', NULL, 0, NULL, 0, 0, 0, 0),
(133, 7, 'Text.jpg', ' nnnnnn nnnnnnnn nnnnnnnn nnnnnnnn nnnn nnnnnnnnn nnnnnnnnn  nnnnnnnnn nnnnnnnnnn nnnnnnnnnnn nnnnnnnnnnn nnnnnnnnnn nnnnn nnnnnn nnnnnn nnnnnnnnnn nnnnnnnnnnnnn nnnnnnnnnnnnn nnnn nnnnnn nnnnnnnnn nnnnnnnnnn nnnnnnnnn nnnnnnnnnn nnnnnnnnnnn nnnnnnnnnnn nnn nnnnnn nnnnnnn nnnnnnn nnnnnn nnnnnnn nnnnnnn nnnnnnnnnn nnnnnnnnnnnnn nnnnnnnnnnnnn nn nnnnnn nnnnnnn nnnnnnn nnnnn nnnnnn nnn nnnnn nnnnnn nnnnn nnnnnn nnnnn nnnn nnnn nnnn nnnn nnnn nnnnnnn nnnnnnn nnnnnnn nnnnnnn  nnnnnnn nnnnn nnnnnn nnnnnnn nnnnnnn nnnnnnnnn nnnnnnn nnnnnn nnnnnnnn nnnnnnnnnn nnnnn nnnnn nnnnnnnnn nnnnnnnnn nnnnnnnnnnnnnn nnnnnnn nnnnnnnnnn nnnnnnnnnnn nnnnnnnnnnn nnnnnnnnnnnn nnnn nnnnnn nnnnnn nnnnnnnnnn nnnnnnnnnnn nnnnnnnnnnnn nnnnnnnnn nnnnnnnnn nnnnnnnn nnnnnnnn nnnnnnnnn nnnnnnnnnn nnnn nnnnnn nnn nnnn nnnnnn nnnnnn nnnnnn nnnnnn nnnnnn nnnnnn nnnnn nnnnn nnnnn nnnnn nnnnn  nnnnn nnnnn  nnnn nnnnnnnn nnnnnnnn nnnnnnnnn nnnnnn nnnn nnnnn nnnn nnnn nnn #me', 'text', '2026-07-24 09:33:58', NULL, 0, NULL, 0, 0, 0, 0),
(137, 3, '1785338117_6a6a190566277_0.jpg', '# i love me some cats thank you', 'image', '2026-07-29 15:15:17', NULL, 0, NULL, 0, 0, 0, 0),
(139, 7, '1785348906_9650.jpg', ' #meeme', 'image', '2026-07-29 18:15:06', NULL, 0, NULL, 0, 0, 0, 0),
(140, 7, '1785353567_2085.jpg', ' #mela', 'image', '2026-07-29 19:32:47', NULL, 0, NULL, 0, 0, 0, 0),
(144, 7, '1785399055_6a6b070f90d7b_0.jpg', '#meme', 'image', '2026-07-30 08:10:55', NULL, 0, NULL, 0, 0, 0, 0),
(147, 7, '1785417263_8788.jpg', ' #me and my day ones ', 'image', '2026-07-30 13:14:23', NULL, 0, NULL, 0, 0, 0, 0);

-- --------------------------------------------------------

--
-- Table structure for table `meme_articles`
--

CREATE TABLE `meme_articles` (
  `id` int(11) NOT NULL,
  `slug` varchar(200) DEFAULT NULL,
  `title` varchar(200) NOT NULL,
  `summary` text DEFAULT NULL,
  `body` longtext DEFAULT NULL,
  `media_url` varchar(500) DEFAULT '',
  `media_type` enum('image','gif','video','nft') DEFAULT 'image',
  `status` enum('trending','rising','classic','dead','revived','legendary') DEFAULT 'rising',
  `category` varchar(100) DEFAULT '',
  `origin_year` varchar(10) DEFAULT '',
  `origin_platform` varchar(100) DEFAULT '',
  `tags` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`tags`)),
  `creator_id` int(11) DEFAULT 0,
  `views` int(11) DEFAULT 0,
  `edit_count` int(11) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(10) UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `meme_articles`
--

INSERT INTO `meme_articles` (`id`, `slug`, `title`, `summary`, `body`, `media_url`, `media_type`, `status`, `category`, `origin_year`, `origin_platform`, `tags`, `creator_id`, `views`, `edit_count`, `created_at`, `updated_at`, `created_by`) VALUES
(1, 'cardi-b-s-i-mean-look-meme', 'Cardi B’s “I Mean, Look” meme', 'Quick Summary\n\nWhat happened?\nCardi B’s courtroom phrase, “I mean, look,” went viral after viewers found her expression and delivery funny and relatable.\n\nWhy is everyone talking about it?\nThe clip became a reaction meme across TikTok, Instagram, and X, with users applying it to everyday situations and arguments.\n\nWas it an important legal moment?\nNot particularly. The viral attention focuses more on Cardi’s delivery and facial expressions than the actual court proceedings.\n\nWhy does it matter?\nIt shows how internet culture can turn a few seconds of real-life footage into a widely shared meme almost overnight.\n\nIn One Sentence\n\nA simple “I mean, look” from Cardi B in court somehow turned into the internet’s newest reaction meme, proving once again that social media can make literally anything go viral.', 'If you’ve been anywhere near TikTok, X, Instagram Reels, Reddit or meme pages lately, chances are you’ve seen the clip. Cardi B, sitting in court, pauses mid-testimony and says:\n\n“I mean, look…\n\nWhat happened next wasn’t dramatic, shocking, or even particularly important to the legal proceedings. Yet somehow, that brief phrase, paired with Cardi’s facial expression and hand gestures, has become one of the internet’s latest viral obsessions.\n\nWhat Happened?\n\nDuring a court appearance, Cardi B was speaking while explaining a point related to the case. As she tried to make her argument, she casually said:\n\n“I mean, look…”\n\nThe phrase itself wasn’t unusual. People say it every day.\n\nThe internet, however, had other plans.\n\nAlmost immediately after clips from the courtroom began circulating online, users started isolating the moment, turning it into reaction videos, memes, edits, GIFs, and lip-sync trends. Many viewers found Cardi’s expression, confidence, and timing unintentionally hilarious.\n\nThe clip spread rapidly across TikTok, where creators began using it whenever they needed visual proof that someone was clearly wrong, being dramatic, or missing an obvious point.\n\nWhy Did It Go Viral?\n\nThe internet loves moments that feel relatable.\n\nCardi’s delivery wasn’t polished like a movie script. It felt natural, spontaneous, and very much like something a friend would say during an argument in a group chat.\n\nPeople started using the clip in situations such as:\n\n* When someone ignores obvious facts.\n* When a friend asks a question with an obvious answer.\n* When trying to prove a point during debates.\n* When explaining why a bad decision was obviously a bad idea.\n\nMany users joked that Cardi accidentally gave the internet a new reaction meme without even trying.\n\nSocial Media Reactions\n\nThe response online was massive.\n\nComments included:\n\n* “She won the argument before she even finished the sentence.”\n* “The hand gestures completed the whole thing.”\n* “This is me trying to explain anything to my friends.”\n* “Cardi just created another meme by existing.”\n\nOthers pointed out that the courtroom setting made the moment even funnier. Courtrooms are usually associated with serious discussions, which made the casually meme-worthy delivery stand out even more.\n\nThe Meme Effect\n\nThis isn’t the first time Cardi B has become meme material.\n\nOver the years, countless clips of the rapper’s interviews, livestreams, and public appearances have become part of internet culture. Her expressive reactions and unfiltered personality often translate perfectly into short-form content.\n\nThe “I mean, look” clip follows a long tradition of viral Cardi moments that end up being used far beyond their original context.\n\nFor many younger internet users, the clip isn’t even about the court case anymore. It’s simply become another reaction image and video format.\n\nImpact\n\nWhile the legal proceedings themselves remain separate from the online jokes, the viral moment highlights how quickly social media can transform a few seconds of footage into a global meme.\n\nIn the age of TikTok and short-form content, even the most ordinary sentence can become internet history if the timing is right.\n\nAnd according to thousands of creators online, Cardi’s “I mean, look” moment had exactly that.', 'uploads/memepedia/meme_6a2858f316f458.36135199.mp4', 'video', 'trending', 'Video Memes', '2025', 'TikTok, X, Instagram Reels', '[]', 2, 10, 1, '2026-06-09 19:18:27', '2026-07-08 05:45:46', NULL),
(2, 'topic-viral-internet-memes-distracted-boyfriend', 'Topic: Viral Internet Memes \"Distracted Boyfriend\"', 'Quick Summary\n\nWhat is Distracted Boyfriend?\nA meme based on a stock photo showing a man looking at another woman while his girlfriend reacts in disbelief.\n\nWhy did it become famous?\nThe image was easy to understand and could be adapted to almost any situation involving distraction, temptation, or changing priorities.\n\nWhere did it come from?\nIt originated from a stock photo taken by Spanish photographer Antonio Guillem in 2015.\n\nWhy is it important?\nIt became one of the most influential and widely used memes in internet history.\n\nIn One Sentence\n\nA random stock photo turned into one of the internet’s most iconic memes, proving that a single glance can sometimes say more than a thousand words.', 'Few memes have achieved the level of fame reached by Distracted Boyfriend. Even people who have never heard the meme’s official name have probably seen it somewhere online. The image became so widespread that it was used by students, brands, celebrities, sports teams, politicians, and pretty much anyone with access to the internet.\n\nWhat started as an ordinary stock photo eventually became one of the defining memes of the late 2010s.\n\nWhat Is Distracted Boyfriend?\n\nThe meme features three people walking down a street.\n\nA man turns around to stare at another woman passing by while his girlfriend looks at him with a mixture of shock, annoyance, and disbelief.\n\nThe image instantly tells a story without needing any explanation.\n\nInternet users quickly realized they could label each person to represent different ideas, turning the photo into a visual joke about distraction, temptation, changing interests, and poor decision-making.\n\nA typical version might look like:\n\n* Boyfriend = Me\n* Girlfriend = Homework\n* Other Woman = Literally Anything Else\n\nAnd just like that, a meme was born.\n\nWhere Did It Come From?\n\nUnlike many famous memes that came from random photos or viral videos, Distracted Boyfriend began life as a stock photo.\n\nThe image was taken by Spanish photographer Antonio Guillem in 2015 and was originally intended for commercial use.\n\nThe models had no idea they were participating in what would eventually become internet history.\n\nFor a while, the photo existed quietly on stock image websites before social media users discovered its meme potential.\n\nOnce the internet got hold of it, there was no going back.\n\nWhy Did It Go Viral?\n\nThe secret to the meme’s success was its simplicity.\n\nPeople immediately understood what was happening.\n\nNo complicated setup.\n\nNo special knowledge required.\n\nJust one glance and everyone knew:\n\n“Yeah… this guy messed up.”\n\nThe image became a perfect template for explaining situations where people abandon one thing for another, often for reasons that make absolutely no sense.\n\nWhether it was procrastination, sports fandom, gaming habits, relationships, or business decisions, the meme somehow fit almost every situation.\n\nSocial Media Reactions\n\nBy 2017, Distracted Boyfriend was everywhere.\n\nTwitter users posted hundreds of versions daily.\n\nInstagram pages shared endless remixes.\n\nReddit communities created increasingly creative interpretations.\n\nSome versions became so detailed that they required entire paragraphs to explain.\n\nOthers worked with only three labels.\n\nThe meme even became popular among companies and brands trying to join online conversations.\n\nAt one point, it felt impossible to spend more than five minutes online without seeing it.\n\nThe Internet Starts Getting Creative\n\nAs the meme grew, users began pushing the format further.\n\nPeople recreated it with pets.\n\nArtists redrew it.\n\nVideo editors animated it.\n\nPhotographers staged real-life versions.\n\nEven museums, universities, and government organizations made their own adaptations.\n\nThe meme had evolved beyond a single image into a full internet language.\n\nCriticism and Discussion\n\nNot everyone loved the meme.\n\nSome people argued it relied on stereotypes about relationships and loyalty.\n\nOthers felt it had become overused after appearing everywhere for months.\n\nIronically, these complaints often became memes themselves.\n\nThe internet couldn’t stop making Distracted Boyfriend jokes—even when joking about how many Distracted Boyfriend jokes existed.\n\nLegacy\n\nYears after its peak popularity, Distracted Boyfriend remains one of the most recognizable memes ever created.\n\nIt is regularly referenced in discussions about internet culture and is often included in lists of the greatest memes of all time.\n\nUnlike many viral trends that disappear after a few weeks, Distracted Boyfriend became part of internet history.\n\nToday, it serves as a reminder that sometimes all it takes is one perfectly timed glance for the internet to create something legendary.', 'uploads/memepedia/meme_6a285c93ef7447.67006339.jpg', 'image', 'legendary', 'Image Memes', '2009', 'All Platforms', '[]', 5, 10, 0, '2026-06-09 19:33:55', '2026-07-07 19:53:36', NULL),
(3, 'pepe-the-frog', 'Pepe the Frog', 'Summary\n\nWhat is Pepe the Frog?\nA green cartoon frog created by artist Matt Furie for the comic Boy’s Club.\n\nWhy did it become famous?\nIts expressive face made it perfect for reaction memes covering almost every emotion imaginable.\n\nWhere did it come from?\nPepe first appeared in Matt Furie’s comic series before spreading across internet forums and social media.\n\nWhy is it important?\nPepe helped shape reaction-image culture and became one of the most influential memes in internet history.\n\nIn One Sentence\n\nA simple cartoon frog evolved into one of the most iconic, recognizable, and long-lasting memes the internet has ever seen.', 'Few memes have had a journey as strange, influential, and complicated as Pepe the Frog.\n\nWhat started as a laid-back cartoon frog in an independent comic eventually became one of the most recognizable faces on the internet. Over the years, Pepe has been everything from a reaction meme and gaming icon to a cultural symbol discussed by news organizations around the world.\n\nFor many internet users, Pepe isn’t just a meme—he’s internet history.\n\nWhat Is Pepe the Frog?\n\nPepe is a green cartoon frog with large eyes and a simple appearance.\n\nHe was originally created by artist Matt Furie as part of his comic series Boy’s Club in the mid-2000s.\n\nOne panel from the comic featured Pepe casually saying:\n\n“Feels good man.”\n\nThat single phrase would end up changing everything.\n\nInternet users began sharing the image online, using it as a reaction to express happiness, relaxation, confidence, sadness, frustration, and pretty much every emotion imaginable.\n\nBefore long, thousands of versions existed.\n\nHow Did Pepe Become a Meme?\n\nPepe’s rise wasn’t instant.\n\nThe character slowly spread through internet forums, message boards, gaming communities, and social media platforms.\n\nUnlike many memes that rely on one specific joke, Pepe worked because he was flexible.\n\nThere was:\n\n* Happy Pepe\n* Sad Pepe\n* Angry Pepe\n* Confused Pepe\n* Smug Pepe\n* Crying Pepe\n\nNo matter how someone felt, there was probably a Pepe for it.\n\nAs internet culture grew, Pepe became one of the most-used reaction images online.\n\nWhy Did People Love It?\n\nThe answer was simple:\n\nPepe felt human.\n\nDespite being a cartoon frog, his expressions captured emotions in a surprisingly relatable way.\n\nUsers could communicate entire moods with a single image.\n\nBefore reaction GIFs and short-form videos dominated social media, Pepe helped people express feelings quickly and visually.\n\nFor many early internet users, Pepe became part of everyday online communication.\n\nThe Complicated Years\n\nAs Pepe’s popularity grew, different online communities began using the character in different ways.\n\nSome uses became controversial, leading to public debates about the meme and its meaning.\n\nThis period brought significant media attention and sparked discussions about how internet culture can change the meaning of a symbol once it spreads widely online.\n\nThroughout this time, creator Matt Furie repeatedly stated that Pepe was originally intended as a positive and friendly character.\n\nThe Return of Pepe\n\nIn later years, many online communities worked to reclaim Pepe’s original identity as a general internet meme.\n\nNew versions focused on humor, nostalgia, gaming culture, and everyday reactions.\n\nPepe once again became a familiar face across platforms like Reddit, Discord, Twitch, X, and countless meme pages.\n\nToday, millions of users know Pepe simply as the frog who perfectly captures whatever emotion they’re feeling.\n\nSocial Media Legacy\n\nPepe has survived multiple generations of internet culture.\n\nHe has existed through:\n\n* Early internet forums\n* Meme pages\n* Gaming communities\n* Streaming culture\n* Social media platforms\n* Modern reaction-image culture\n\nVery few memes remain relevant for as long as Pepe has.\n\nWhile trends come and go, Pepe continues appearing in new formats year after year.\n\nImpact\n\nPepe the Frog is widely considered one of the most influential memes ever created.\n\nThe character helped define reaction-image culture and showed how internet communities can transform a simple comic character into a global phenomenon.\n\nWhether people know him from old message boards, Twitch chats, Discord servers, or modern meme pages, Pepe remains one of the internet’s most recognizable faces.', 'uploads/memepedia/meme_6a285e549fddb3.03368806.jpg', 'image', 'rising', 'Classic Internet', '2005', 'all', '[]', 2, 30, 0, '2026-06-09 19:41:24', '2026-07-07 19:28:51', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `meme_article_tags`
--

CREATE TABLE `meme_article_tags` (
  `article_id` int(11) NOT NULL,
  `tag` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `meme_audio`
--

CREATE TABLE `meme_audio` (
  `id` int(11) NOT NULL,
  `meme_id` int(10) UNSIGNED NOT NULL,
  `audio_id` int(11) NOT NULL,
  `music_volume` tinyint(3) UNSIGNED NOT NULL DEFAULT 80,
  `video_audio_volume` tinyint(3) UNSIGNED NOT NULL DEFAULT 40,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `meme_audio`
--

INSERT INTO `meme_audio` (`id`, `meme_id`, `audio_id`, `music_volume`, `video_audio_volume`, `created_at`) VALUES
(5, 139, 1, 80, 40, '2026-07-29 18:15:06'),
(6, 140, 1, 80, 40, '2026-07-29 19:32:47'),
(8, 144, 1, 80, 0, '2026-07-30 08:10:55'),
(10, 147, 1, 80, 0, '2026-07-30 13:14:23');

-- --------------------------------------------------------

--
-- Table structure for table `meme_comments`
--

CREATE TABLE `meme_comments` (
  `id` int(11) NOT NULL,
  `article_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `username` varchar(100) NOT NULL,
  `avatar` varchar(500) DEFAULT '',
  `text` text NOT NULL,
  `posted_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `meme_comments`
--

INSERT INTO `meme_comments` (`id`, `article_id`, `user_id`, `username`, `avatar`, `text`, `posted_at`) VALUES
(1, 2, 2, 'Dani', 'uploads/1774069944_Dani.jpg', 'The memes origin is actually 2015', '2026-06-09 19:42:33');

-- --------------------------------------------------------

--
-- Table structure for table `meme_edits`
--

CREATE TABLE `meme_edits` (
  `id` int(11) NOT NULL,
  `article_id` int(11) NOT NULL,
  `editor_id` int(11) NOT NULL,
  `old_body` longtext DEFAULT NULL,
  `new_body` longtext DEFAULT NULL,
  `edit_summary` varchar(300) DEFAULT '',
  `edited_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `meme_edits`
--

INSERT INTO `meme_edits` (`id`, `article_id`, `editor_id`, `old_body`, `new_body`, `edit_summary`, `edited_at`) VALUES
(1, 1, 5, 'If you’ve been anywhere near TikTok, X, Instagram Reels, or meme pages lately, chances are you’ve seen the clip. Cardi B, sitting in court, pauses mid-testimony and says:\n\n“I mean, look…”\n\nWhat happened next wasn’t dramatic, shocking, or even particularly important to the legal proceedings. Yet somehow, that brief phrase, paired with Cardi’s facial expression and hand gestures, has become one of the internet’s latest viral obsessions.\n\nWhat Happened?\n\nDuring a court appearance, Cardi B was speaking while explaining a point related to the case. As she tried to make her argument, she casually said:\n\n“I mean, look…”\n\nThe phrase itself wasn’t unusual. People say it every day.\n\nThe internet, however, had other plans.\n\nAlmost immediately after clips from the courtroom began circulating online, users started isolating the moment, turning it into reaction videos, memes, edits, GIFs, and lip-sync trends. Many viewers found Cardi’s expression, confidence, and timing unintentionally hilarious.\n\nThe clip spread rapidly across TikTok, where creators began using it whenever they needed visual proof that someone was clearly wrong, being dramatic, or missing an obvious point.\n\nWhy Did It Go Viral?\n\nThe internet loves moments that feel relatable.\n\nCardi’s delivery wasn’t polished like a movie script. It felt natural, spontaneous, and very much like something a friend would say during an argument in a group chat.\n\nPeople started using the clip in situations such as:\n\n* When someone ignores obvious facts.\n* When a friend asks a question with an obvious answer.\n* When trying to prove a point during debates.\n* When explaining why a bad decision was obviously a bad idea.\n\nMany users joked that Cardi accidentally gave the internet a new reaction meme without even trying.\n\nSocial Media Reactions\n\nThe response online was massive.\n\nComments included:\n\n* “She won the argument before she even finished the sentence.”\n* “The hand gestures completed the whole thing.”\n* “This is me trying to explain anything to my friends.”\n* “Cardi just created another meme by existing.”\n\nOthers pointed out that the courtroom setting made the moment even funnier. Courtrooms are usually associated with serious discussions, which made the casually meme-worthy delivery stand out even more.\n\nThe Meme Effect\n\nThis isn’t the first time Cardi B has become meme material.\n\nOver the years, countless clips of the rapper’s interviews, livestreams, and public appearances have become part of internet culture. Her expressive reactions and unfiltered personality often translate perfectly into short-form content.\n\nThe “I mean, look” clip follows a long tradition of viral Cardi moments that end up being used far beyond their original context.\n\nFor many younger internet users, the clip isn’t even about the court case anymore. It’s simply become another reaction image and video format.\n\nImpact\n\nWhile the legal proceedings themselves remain separate from the online jokes, the viral moment highlights how quickly social media can transform a few seconds of footage into a global meme.\n\nIn the age of TikTok and short-form content, even the most ordinary sentence can become internet history if the timing is right.\n\nAnd according to thousands of creators online, Cardi’s “I mean, look” moment had exactly that.', 'If you’ve been anywhere near TikTok, X, Instagram Reels, Reddit or meme pages lately, chances are you’ve seen the clip. Cardi B, sitting in court, pauses mid-testimony and says:\n\n“I mean, look…\n\nWhat happened next wasn’t dramatic, shocking, or even particularly important to the legal proceedings. Yet somehow, that brief phrase, paired with Cardi’s facial expression and hand gestures, has become one of the internet’s latest viral obsessions.\n\nWhat Happened?\n\nDuring a court appearance, Cardi B was speaking while explaining a point related to the case. As she tried to make her argument, she casually said:\n\n“I mean, look…”\n\nThe phrase itself wasn’t unusual. People say it every day.\n\nThe internet, however, had other plans.\n\nAlmost immediately after clips from the courtroom began circulating online, users started isolating the moment, turning it into reaction videos, memes, edits, GIFs, and lip-sync trends. Many viewers found Cardi’s expression, confidence, and timing unintentionally hilarious.\n\nThe clip spread rapidly across TikTok, where creators began using it whenever they needed visual proof that someone was clearly wrong, being dramatic, or missing an obvious point.\n\nWhy Did It Go Viral?\n\nThe internet loves moments that feel relatable.\n\nCardi’s delivery wasn’t polished like a movie script. It felt natural, spontaneous, and very much like something a friend would say during an argument in a group chat.\n\nPeople started using the clip in situations such as:\n\n* When someone ignores obvious facts.\n* When a friend asks a question with an obvious answer.\n* When trying to prove a point during debates.\n* When explaining why a bad decision was obviously a bad idea.\n\nMany users joked that Cardi accidentally gave the internet a new reaction meme without even trying.\n\nSocial Media Reactions\n\nThe response online was massive.\n\nComments included:\n\n* “She won the argument before she even finished the sentence.”\n* “The hand gestures completed the whole thing.”\n* “This is me trying to explain anything to my friends.”\n* “Cardi just created another meme by existing.”\n\nOthers pointed out that the courtroom setting made the moment even funnier. Courtrooms are usually associated with serious discussions, which made the casually meme-worthy delivery stand out even more.\n\nThe Meme Effect\n\nThis isn’t the first time Cardi B has become meme material.\n\nOver the years, countless clips of the rapper’s interviews, livestreams, and public appearances have become part of internet culture. Her expressive reactions and unfiltered personality often translate perfectly into short-form content.\n\nThe “I mean, look” clip follows a long tradition of viral Cardi moments that end up being used far beyond their original context.\n\nFor many younger internet users, the clip isn’t even about the court case anymore. It’s simply become another reaction image and video format.\n\nImpact\n\nWhile the legal proceedings themselves remain separate from the online jokes, the viral moment highlights how quickly social media can transform a few seconds of footage into a global meme.\n\nIn the age of TikTok and short-form content, even the most ordinary sentence can become internet history if the timing is right.\n\nAnd according to thousands of creators online, Cardi’s “I mean, look” moment had exactly that.', 'Updated article content', '2026-06-09 19:20:03');

-- --------------------------------------------------------

--
-- Table structure for table `meme_media`
--

CREATE TABLE `meme_media` (
  `id` int(10) UNSIGNED NOT NULL,
  `meme_id` int(10) UNSIGNED NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `type` enum('image','video','gif') NOT NULL,
  `position` int(10) UNSIGNED NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `meme_media`
--

INSERT INTO `meme_media` (`id`, `meme_id`, `file_name`, `type`, `position`) VALUES
(15, 124, '1784547716_1238_1.jpg', 'image', 1),
(16, 124, '1784547716_9138_2.jpg', 'image', 2),
(17, 124, '1784547716_6121_3.jpg', 'image', 3),
(18, 124, '1784547716_8652_4.jpg', 'image', 4),
(19, 124, '1784547716_4161_5.jpg', 'image', 5),
(20, 124, '1784547716_4009_6.gif', 'gif', 6),
(21, 124, '1784547716_3226_7.jpg', 'image', 7),
(22, 124, '1784547716_9614_8.jpg', 'image', 8),
(23, 124, '1784547716_8274_9.jpg', 'image', 9),
(36, 137, '1785338117_6a6a190567bbe_1.jpg', 'image', 1),
(37, 137, '1785338117_6a6a190568e9d_2.jpg', 'image', 2),
(38, 137, '1785338117_6a6a19056a047_3.jpg', 'image', 3),
(39, 137, '1785338117_6a6a19056b3d0_4.jpg', 'image', 4),
(40, 137, '1785338117_6a6a19056c6a7_5.jpg', 'image', 5),
(41, 137, '1785338117_6a6a19056d931_6.jpg', 'image', 6),
(42, 140, '1785353567_5935_1.jpg', 'image', 1),
(43, 140, '1785353567_7525_2.jpg', 'image', 2),
(44, 140, '1785353567_6958_3.jpg', 'image', 3),
(45, 140, '1785353567_3578_4.jpg', 'image', 4),
(46, 140, '1785353567_8455_5.jpg', 'image', 5),
(47, 140, '1785353567_3615_6.jpg', 'image', 6),
(48, 140, '1785353567_3936_7.jpg', 'image', 7),
(86, 144, '1785399055_6a6b070f91929_1.jpg', 'image', 1),
(87, 144, '1785399055_6a6b070f923a1_2.jpg', 'image', 2),
(88, 144, '1785399055_6a6b070f92f90_3.jpg', 'image', 3),
(89, 144, '1785399055_6a6b070f9378d_4.jpg', 'image', 4),
(90, 144, '1785399055_6a6b070f94044_5.jpg', 'image', 5),
(91, 144, '1785399055_6a6b070f94c3d_6.jpg', 'image', 6),
(92, 144, '1785399055_6a6b070f95449_7.jpg', 'image', 7),
(93, 144, '1785399055_6a6b070f95c17_8.jpg', 'image', 8),
(94, 144, '1785399055_6a6b070f96eb0_9.jpg', 'image', 9),
(95, 144, '1785399055_6a6b070f97d6e_10.jpg', 'image', 10),
(96, 144, '1785399055_6a6b070f98aa7_11.png', 'image', 11),
(97, 144, '1785399055_6a6b070f996a8_12.jpg', 'image', 12),
(98, 144, '1785399055_6a6b070f99f47_13.mp4', 'video', 13),
(99, 144, '1785399055_6a6b070f9a7bf_14.jpg', 'image', 14),
(100, 144, '1785399055_6a6b070f9af9e_15.jpg', 'image', 15),
(101, 144, '1785399055_6a6b070f9b723_16.mp4', 'video', 16),
(102, 144, '1785399055_6a6b070f9beb0_17.jpg', 'image', 17),
(103, 144, '1785399055_6a6b070f9cc84_18.jpg', 'image', 18),
(104, 144, '1785399055_6a6b070f9d8e7_19.jpg', 'image', 19),
(125, 147, '1785417263_4926_1.jpg', 'image', 1),
(126, 147, '1785417263_2197_2.jpg', 'image', 2),
(127, 147, '1785417263_9789_3.jpg', 'image', 3),
(128, 147, '1785417263_3978_4.jpg', 'image', 4),
(129, 147, '1785417263_9493_5.jpg', 'image', 5),
(130, 147, '1785417263_7529_6.jpg', 'image', 6),
(131, 147, '1785417263_6637_7.jpg', 'image', 7),
(132, 147, '1785417263_1020_8.jpg', 'image', 8),
(133, 147, '1785417263_9887_9.jpg', 'image', 9),
(134, 147, '1785417263_7973_10.jpg', 'image', 10),
(135, 147, '1785417263_3901_11.jpg', 'image', 11),
(136, 147, '1785417263_4076_12.png', 'image', 12),
(137, 147, '1785417263_2861_13.jpg', 'image', 13),
(138, 147, '1785417263_1768_14.mp4', 'video', 14),
(139, 147, '1785417263_1149_15.jpg', 'image', 15),
(140, 147, '1785417263_4873_16.jpg', 'image', 16),
(141, 147, '1785417263_6131_17.mp4', 'video', 17),
(142, 147, '1785417263_8286_18.jpg', 'image', 18),
(143, 147, '1785417263_7799_19.jpg', 'image', 19);

-- --------------------------------------------------------

--
-- Table structure for table `messages`
--

CREATE TABLE `messages` (
  `id` int(10) UNSIGNED NOT NULL,
  `sender_id` int(10) UNSIGNED NOT NULL,
  `receiver_id` int(10) UNSIGNED NOT NULL,
  `message` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `file_attachment` varchar(300) DEFAULT NULL,
  `seen` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `messages`
--

INSERT INTO `messages` (`id`, `sender_id`, `receiver_id`, `message`, `created_at`, `file_attachment`, `seen`) VALUES
(1, 5, 3, '[deleted]', '2026-03-22 14:41:43', NULL, 1),
(2, 5, 3, 'Hello How was your day', '2026-03-22 14:42:43', NULL, 1),
(3, 3, 5, 'Hi my day was good for the most part lol', '2026-03-22 15:21:47', NULL, 1),
(4, 5, 3, 'ohhh, Ok thats good to know :)', '2026-03-23 10:13:01', NULL, 1),
(7, 2, 3, 'Hi leah i love the profile pic', '2026-03-25 18:17:39', NULL, 1),
(8, 3, 2, 'Hiiiiii thank you so much', '2026-03-26 09:19:07', NULL, 1),
(9, 2, 1, 'Yo Tyven your Bio is so funny', '2026-03-26 12:41:46', NULL, 1),
(10, 3, 2, 'I also love ur\'s toooo', '2026-04-01 06:08:49', NULL, 1),
(11, 2, 7, 'hola', '2026-04-11 05:42:16', NULL, 1),
(12, 3, 8, 'Hi chelsey ur so pretty', '2026-04-20 14:08:03', NULL, 1),
(13, 3, 2, 'Dani how are you', '2026-04-20 14:08:38', NULL, 1),
(14, 8, 3, 'hi leah you too', '2026-04-20 14:14:22', NULL, 1),
(15, 8, 3, 'we should meet up sometime', '2026-04-20 14:14:39', NULL, 1),
(17, 1, 2, 'Thank man i try!!!', '2026-05-11 09:19:34', NULL, 1),
(20, 2, 1, 'awesome', '2026-05-11 09:46:50', NULL, 1),
(22, 2, 1, 'so will you like to collab some day', '2026-05-11 10:06:19', NULL, 1),
(23, 2, 1, '[deleted]', '2026-05-11 11:15:15', NULL, 1),
(24, 2, 1, '[deleted]', '2026-05-11 11:28:33', NULL, 1),
(28, 2, 3, 'yh leah im good just trying to put my self together', '2026-05-19 06:24:07', NULL, 1),
(29, 2, 3, 'just trying to be better', '2026-05-19 06:24:27', NULL, 1),
(30, 2, 3, 'And i hope your doing good you have not been posting for a while now', '2026-05-19 06:41:49', NULL, 1),
(31, 3, 2, 'yes dani im doing fine just being also caught up and busy lately', '2026-05-19 07:06:51', NULL, 1),
(32, 3, 8, 'yh Chelsey we definetly should meet up', '2026-05-19 07:07:17', NULL, 1),
(33, 3, 8, 'But now its where exactly', '2026-05-19 07:07:28', NULL, 1),
(34, 3, 2, 'Maybe one of thisdays you, Chelsey and I should meet up and hangout', '2026-05-19 07:08:16', NULL, 1),
(35, 4, 2, 'Whats up Dani how you doing', '2026-05-19 10:26:15', NULL, 1),
(65, 2, 4, 'hi', '2026-06-13 08:21:35', NULL, 1),
(66, 2, 1, 'hola', '2026-06-13 08:22:26', NULL, 1),
(67, 2, 1, 'how are u tyven', '2026-06-13 08:22:50', NULL, 1),
(68, 2, 1, 'hope your good :)', '2026-06-13 08:23:02', NULL, 1),
(69, 2, 1, 'sorry for the invite i didnt know you where part of the group', '2026-06-13 08:27:15', NULL, 1),
(70, 1, 2, 'no no its cool dani im good you', '2026-06-13 08:28:38', NULL, 1),
(71, 1, 2, 'how the holiday', '2026-06-13 08:29:07', NULL, 1),
(72, 2, 1, 'its going good', '2026-06-13 08:29:43', NULL, 1),
(73, 1, 2, 'ok thats good to know', '2026-06-13 08:30:18', NULL, 1),
(74, 2, 1, 'yh', '2026-06-13 08:32:28', NULL, 1),
(75, 1, 2, ':)', '2026-06-13 08:32:55', NULL, 1),
(76, 1, 2, '[deleted]', '2026-06-13 14:11:54', NULL, 1),
(77, 1, 2, 'this is on memepedia', '2026-06-13 14:13:11', 'uploads/messages/6a2d65774398f.jpg', 1),
(85, 1, 3, 'Whats up leah', '2026-06-17 15:11:30', NULL, 1);

-- --------------------------------------------------------

--
-- Table structure for table `message_hidden`
--

CREATE TABLE `message_hidden` (
  `id` int(10) UNSIGNED NOT NULL,
  `message_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `message_reactions`
--

CREATE TABLE `message_reactions` (
  `id` int(10) UNSIGNED NOT NULL,
  `message_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `emoji` varchar(10) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `muted_groups`
--

CREATE TABLE `muted_groups` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `group_id` int(10) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `muted_users`
--

CREATE TABLE `muted_users` (
  `id` int(10) UNSIGNED NOT NULL,
  `muter_id` int(10) UNSIGNED NOT NULL,
  `muted_id` int(10) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `actor_id` int(11) DEFAULT NULL,
  `type` varchar(50) DEFAULT NULL,
  `ref_id` int(11) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `is_read` tinyint(4) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `actor_id`, `type`, `ref_id`, `message`, `is_read`, `created_at`) VALUES
(1, 7, NULL, 'group_invite', 4, 'Leah invited you to join the Pod: Bad Girls Club', 0, '2026-06-12 15:16:23'),
(2, 7, 3, 'group_invite', 4, 'Leah invited you to join the Pod: Bad Girls Club', 0, '2026-06-12 16:22:55'),
(3, 7, 3, 'group_invite', 4, 'Leah invited you to join the Pod: Bad Girls Club', 0, '2026-06-12 17:00:56'),
(4, 5, 3, 'group_invite', 4, 'Leah invited you to join the Pod: Bad Girls Club', 0, '2026-06-12 17:01:38'),
(5, 13, 3, 'group_invite', 4, 'Leah invited you to join the Pod: Bad Girls Club', 0, '2026-06-12 20:35:57'),
(6, 3, 2, 'group_invite', 1, 'Dani invited you to join the Pod: Funny memes ', 1, '2026-06-12 23:14:10'),
(7, 1, 2, 'group_invite', 4, 'Dani invited you to join the Pod: Bad Girls Club', 1, '2026-06-13 08:24:02'),
(8, 3, 1, 'group_invite', 1, 'Tyven invited you to join the Pod: Funny memes ', 0, '2026-06-13 09:35:49'),
(9, 16, 2, 'group_invite', 2, 'Dani invited you to join the Pod: Cardi Fan page', 1, '2026-06-16 06:53:26'),
(10, 3, 1, 'group_invite', 4, 'Tyven invited you to join the Pod: Bad Girls Club', 1, '2026-06-17 15:19:03');

-- --------------------------------------------------------

--
-- Table structure for table `notification_reads`
--

CREATE TABLE `notification_reads` (
  `user_id` int(11) NOT NULL,
  `last_read_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notification_reads`
--

INSERT INTO `notification_reads` (`user_id`, `last_read_at`) VALUES
(2, '2026-07-05 14:55:36'),
(3, '2026-07-05 18:53:02'),
(7, '2026-07-05 14:13:14'),
(8, '2026-07-05 14:09:00'),
(16, '2026-07-05 15:04:16');

-- --------------------------------------------------------

--
-- Table structure for table `notification_seen`
--

CREATE TABLE `notification_seen` (
  `user_id` int(11) NOT NULL,
  `seen_date` date NOT NULL,
  `seen_count` int(11) NOT NULL DEFAULT 0,
  `seen_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notification_seen`
--

INSERT INTO `notification_seen` (`user_id`, `seen_date`, `seen_count`, `seen_at`) VALUES
(1, '2026-06-13', 8, '2026-06-13 08:55:27'),
(1, '2026-06-14', 2, '2026-06-14 18:29:37'),
(1, '2026-06-15', 1, '2026-06-15 00:56:08'),
(1, '2026-06-16', 4, '2026-06-16 17:00:13'),
(1, '2026-06-17', 0, '2026-06-17 04:57:29'),
(1, '2026-06-19', 0, '2026-06-19 05:30:39'),
(1, '2026-07-05', 0, '2026-07-05 07:39:10'),
(2, '2026-06-13', 10, '2026-06-13 08:34:11'),
(2, '2026-06-15', 6, '2026-06-15 16:33:45'),
(2, '2026-06-16', 1, '2026-06-16 06:52:09'),
(2, '2026-06-18', 999999, '2026-06-18 17:48:09'),
(2, '2026-06-19', 0, '2026-06-19 05:17:58'),
(2, '2026-06-20', 0, '2026-06-20 04:15:02'),
(2, '2026-06-21', 2, '2026-06-21 13:32:54'),
(2, '2026-06-22', 4, '2026-06-22 12:11:55'),
(2, '2026-06-27', 3, '2026-06-27 10:31:29'),
(2, '2026-07-05', 1, '2026-07-05 07:17:15'),
(3, '2026-06-13', 14, '2026-06-13 07:19:11'),
(3, '2026-06-14', 3, '2026-06-14 19:33:37'),
(3, '2026-06-16', 7, '2026-06-16 09:51:42'),
(3, '2026-06-17', 7, '2026-06-17 15:21:33'),
(3, '2026-06-20', 1, '2026-06-20 12:56:18'),
(3, '2026-06-21', 1, '2026-06-21 07:35:55'),
(3, '2026-06-22', 4, '2026-06-22 11:20:38'),
(3, '2026-06-23', 0, '2026-06-23 03:47:21'),
(3, '2026-06-27', 1, '2026-06-27 06:23:26'),
(3, '2026-07-07', 0, '2026-07-07 07:11:58'),
(3, '2026-07-09', 2, '2026-07-09 15:40:36'),
(4, '2026-06-13', 9, '2026-06-13 08:20:26'),
(4, '2026-06-14', 5, '2026-06-14 19:57:39'),
(4, '2026-06-16', 3, '2026-06-16 07:08:59'),
(4, '2026-06-19', 5, '2026-06-19 14:00:25'),
(4, '2026-06-22', 8, '2026-06-22 11:47:25'),
(5, '2026-06-13', 10, '2026-06-13 08:04:24'),
(7, '2026-06-13', 0, '2026-06-12 23:02:56'),
(7, '2026-06-15', 2, '2026-06-15 07:10:29'),
(7, '2026-07-05', 3, '2026-07-05 13:23:01'),
(7, '2026-07-09', 2, '2026-07-09 15:03:30'),
(8, '2026-06-15', 0, '2026-06-15 07:17:27'),
(8, '2026-06-27', 1, '2026-06-27 17:04:14'),
(8, '2026-07-01', 0, '2026-07-01 19:30:17'),
(8, '2026-07-02', 2, '2026-07-02 07:38:24'),
(13, '2026-06-13', 1, '2026-06-13 00:09:15'),
(13, '2026-06-16', 0, '2026-06-16 05:28:06'),
(13, '2026-06-18', 0, '2026-06-18 06:47:36'),
(13, '2026-06-19', 0, '2026-06-19 06:11:32'),
(13, '2026-06-22', 1, '2026-06-22 11:21:27'),
(14, '2026-06-16', 0, '2026-06-16 05:36:11'),
(16, '2026-06-16', 11, '2026-06-16 11:58:55');

-- --------------------------------------------------------

--
-- Table structure for table `orbiter_scores`
--

CREATE TABLE `orbiter_scores` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `target_id` int(11) NOT NULL,
  `actor_id` int(11) NOT NULL,
  `score` float NOT NULL DEFAULT 0,
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `page_admins`
--

CREATE TABLE `page_admins` (
  `id` int(10) UNSIGNED NOT NULL,
  `page_user_id` int(10) UNSIGNED NOT NULL,
  `admin_user_id` int(10) UNSIGNED NOT NULL,
  `role` enum('owner','administrator','editor','moderator','analyst') NOT NULL DEFAULT 'editor',
  `appointed_by` int(10) UNSIGNED NOT NULL,
  `appointed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `page_details`
--

CREATE TABLE `page_details` (
  `user_id` int(10) UNSIGNED NOT NULL,
  `category` varchar(60) DEFAULT NULL,
  `short_description` varchar(160) DEFAULT NULL,
  `long_description` text DEFAULT NULL,
  `website` varchar(255) DEFAULT NULL,
  `contact_email` varchar(150) DEFAULT NULL,
  `contact_phone` varchar(30) DEFAULT NULL,
  `is_verified` tinyint(1) NOT NULL DEFAULT 0,
  `verified_at` timestamp NULL DEFAULT NULL,
  `founded_date` date DEFAULT NULL,
  `created_by` int(10) UNSIGNED NOT NULL,
  `status` enum('active','suspended','pending_review') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `password_reset_tokens`
--

CREATE TABLE `password_reset_tokens` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `token_hash` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `password_reset_tokens`
--

INSERT INTO `password_reset_tokens` (`id`, `user_id`, `token_hash`, `expires_at`, `used`, `created_at`) VALUES
(1, 3, '95ce99d47f49998a4be34766acedc50453faa3fa851cddc531d236545ab1dcfa', '2026-06-03 17:09:03', 0, '2026-06-03 15:54:03');

-- --------------------------------------------------------

--
-- Table structure for table `pinned_collections`
--

CREATE TABLE `pinned_collections` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `collection_id` int(11) NOT NULL,
  `pinned_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pinned_posts`
--

CREATE TABLE `pinned_posts` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `meme_id` int(11) NOT NULL,
  `pinned_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `pinned_posts`
--

INSERT INTO `pinned_posts` (`id`, `user_id`, `meme_id`, `pinned_at`) VALUES
(4, 3, 3, '2026-06-20 13:44:45'),
(5, 4, 6, '2026-06-20 13:45:53');

-- --------------------------------------------------------

--
-- Table structure for table `pod_discussions`
--

CREATE TABLE `pod_discussions` (
  `id` int(11) NOT NULL,
  `group_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `body` text DEFAULT NULL,
  `tag` varchar(30) DEFAULT 'Discussion',
  `poll_type` enum('vote','quiz') DEFAULT NULL,
  `poll_expires_at` datetime DEFAULT NULL,
  `media_file` varchar(255) DEFAULT '',
  `media_type` enum('none','image','video','gif') DEFAULT 'none',
  `is_pinned` tinyint(1) DEFAULT 0,
  `is_locked` tinyint(1) DEFAULT 0,
  `reply_count` int(11) DEFAULT 0,
  `view_count` int(11) DEFAULT 0,
  `last_activity` datetime DEFAULT current_timestamp(),
  `created_at` datetime DEFAULT current_timestamp(),
  `comments_disabled` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `pod_discussions`
--

INSERT INTO `pod_discussions` (`id`, `group_id`, `user_id`, `title`, `body`, `tag`, `poll_type`, `poll_expires_at`, `media_file`, `media_type`, `is_pinned`, `is_locked`, `reply_count`, `view_count`, `last_activity`, `created_at`, `comments_disabled`) VALUES
(3, 2, 2, 'I  want to ask something', '', 'Question', NULL, NULL, '1780947664_1774085524_photo_2026-03-21_10-26-51.jpg', 'image', 0, 0, 4, 10, '2026-06-09 20:02:33', '2026-06-08 20:41:04', 0),
(7, 2, 2, 'me', '', 'Poll', NULL, NULL, '', 'none', 0, 0, 0, 4, '2026-07-13 10:05:24', '2026-07-13 10:05:24', 0),
(10, 2, 2, 'What is Cardi B\'s real name', 'Take a guess', 'Poll', 'quiz', NULL, '', 'none', 0, 0, 0, 9, '2026-07-13 10:39:52', '2026-07-13 10:39:52', 0),
(11, 1, 4, 'One meme format forever?', 'Okay so genuinely serious question that has been keeping me up at night for the past three days and I need the pod\'s collective wisdom on this because I brought it up to my roommate and he looked at me like I had lost my mind, but like, if you had to pick exactly ONE meme format to be legally binding as the official reaction to every single bad thing that happens to you for the rest of your life — jobs falling through, texts left on read, stepping on Legos, existential dread at 2am, whatever — and you could never use any other meme ever again after that, which one are you picking and WHY, because I feel like this says more about a person than their zodiac sign ever could. No wrong answers but also there are definitely wrong answers.', 'Question', 'vote', NULL, '', 'none', 0, 0, 1, 1, '2026-07-13 13:38:44', '2026-07-13 13:37:54', 0),
(12, 1, 2, 'Meme that lives in my head rent-free', 'So I need to know if I\'m the only unhinged one here but there is this one meme that I have thought about no joke at least once a day for the past two years, like I\'ll be in the middle of a completely unrelated conversation, doing groceries, in a work meeting, whatever, and it just resurfaces in my brain completely unprompted and I have to physically stop myself from laughing out loud in public like a lunatic, and at this point I genuinely think it has rewired something in my brain permanently, so tell me — what\'s the meme that has taken up permanent, undeserved, rent-free residence in your skull and refuses to leave no matter how much time passes or how unfunny it should logically be by now?', 'Meme Talk', 'vote', NULL, '1783946621_Woman yelling at cat Template.jpg', 'image', 0, 0, 0, 0, '2026-07-13 13:43:41', '2026-07-13 13:43:41', 0),
(13, 1, 2, 'What is Your Prefrence', '', 'Poll', 'vote', NULL, '', 'none', 0, 0, 2, 19, '2026-07-13 14:05:15', '2026-07-13 13:50:25', 0),
(16, 1, 4, 'The list of my most funny memes', 'These are all the mems i find personal funny lol if you agree you can like there are still plenty more i find funny but theses the only ones i could find for noe iconic memes', 'Meme Talk', 'vote', NULL, '', 'none', 0, 0, 0, 0, '2026-07-14 05:17:12', '2026-07-14 05:17:12', 0);

-- --------------------------------------------------------

--
-- Table structure for table `pod_discussion_media`
--

CREATE TABLE `pod_discussion_media` (
  `id` int(11) NOT NULL,
  `discussion_id` int(11) NOT NULL,
  `file` varchar(255) NOT NULL,
  `type` enum('image','gif','video') NOT NULL DEFAULT 'image',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `pod_discussion_media`
--

INSERT INTO `pod_discussion_media` (`id`, `discussion_id`, `file`, `type`, `sort_order`, `created_at`) VALUES
(1, 3, '1780947664_1774085524_photo_2026-03-21_10-26-51.jpg', 'image', 0, '2026-07-13 17:26:07'),
(2, 12, '1783946621_Woman yelling at cat Template.jpg', 'image', 0, '2026-07-13 17:26:07'),
(11, 16, '1784002632_0_457 ✷ Squid Game.jpg', 'image', 0, '2026-07-14 04:17:12'),
(12, 16, '1784002632_1_download (54).jpg', 'image', 1, '2026-07-14 04:17:12'),
(13, 16, '1784002632_2_meme.jpg', 'image', 2, '2026-07-14 04:17:12'),
(14, 16, '1784002632_3_Please do my quiz my pretties!.jpg', 'image', 3, '2026-07-14 04:17:12'),
(15, 16, '1784002632_4_Where is Popeyes meme kid Dieunerst Collin now_.jpg', 'image', 4, '2026-07-14 04:17:12'),
(16, 16, '1784002632_5_download.gif', 'gif', 5, '2026-07-14 04:17:12');

-- --------------------------------------------------------

--
-- Table structure for table `pod_discussion_replies`
--

CREATE TABLE `pod_discussion_replies` (
  `id` int(11) NOT NULL,
  `discussion_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `content` text DEFAULT NULL,
  `media_file` varchar(255) DEFAULT '',
  `media_type` enum('none','image','video','gif') DEFAULT 'none',
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `pod_discussion_replies`
--

INSERT INTO `pod_discussion_replies` (`id`, `discussion_id`, `user_id`, `parent_id`, `content`, `media_file`, `media_type`, `created_at`) VALUES
(6, 3, 4, NULL, 'ok yh what your question', '', 'none', '2026-06-08 20:43:20'),
(7, 3, 1, 6, 'yh how you gonna comment something and not ask your question :) lol', '', 'none', '2026-06-08 20:44:56'),
(8, 3, 2, 6, 'I was gonna ask where i can get the meme i want to know its origin', '', 'none', '2026-06-09 20:00:50'),
(9, 3, 5, 8, 'You can find its origins on memepedia on the platform it should help you', '', 'none', '2026-06-09 20:02:33'),
(10, 11, 2, NULL, 'Drake pointing meme. Always has been', '', 'none', '2026-07-13 13:38:44'),
(11, 13, 4, NULL, 'me personally i prefer funny Audios because most of the times they cant be remixed', '', 'none', '2026-07-13 13:52:19'),
(13, 13, 2, NULL, 'Lol me i prefer images because you can understand the context of a meme just from the image or video or GIF compared to audios that are usually language limited', '', 'none', '2026-07-13 14:05:15');

-- --------------------------------------------------------

--
-- Table structure for table `pod_discussion_reports`
--

CREATE TABLE `pod_discussion_reports` (
  `id` int(11) NOT NULL,
  `group_id` int(11) NOT NULL,
  `discussion_id` int(11) NOT NULL,
  `reported_by` int(11) NOT NULL,
  `reported_user_id` int(11) NOT NULL,
  `reason` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pod_likes`
--

CREATE TABLE `pod_likes` (
  `id` int(11) NOT NULL,
  `discussion_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `type` enum('like','dislike') NOT NULL DEFAULT 'like',
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `pod_likes`
--

INSERT INTO `pod_likes` (`id`, `discussion_id`, `user_id`, `type`, `created_at`) VALUES
(1, 1, 1, 'like', '2026-06-02 13:44:22'),
(2, 2, 2, 'like', '2026-06-05 08:22:39'),
(3, 2, 1, 'like', '2026-06-08 18:50:47'),
(4, 3, 1, 'like', '2026-06-08 20:45:17'),
(5, 3, 2, 'like', '2026-06-09 20:04:12'),
(6, 3, 5, 'like', '2026-06-09 20:04:19'),
(7, 3, 8, 'like', '2026-07-01 20:18:21'),
(8, 10, 7, 'like', '2026-07-13 10:47:55'),
(9, 7, 7, 'like', '2026-07-13 10:47:57'),
(10, 10, 2, 'like', '2026-07-13 10:48:24'),
(11, 7, 2, 'like', '2026-07-13 10:48:26'),
(12, 11, 2, 'like', '2026-07-13 13:38:49'),
(13, 11, 4, 'like', '2026-07-13 13:41:21'),
(14, 12, 2, 'like', '2026-07-13 13:44:46'),
(15, 13, 2, 'like', '2026-07-13 14:37:53'),
(16, 16, 2, 'like', '2026-07-14 05:18:11'),
(17, 16, 3, 'like', '2026-07-15 13:59:30'),
(18, 13, 3, 'like', '2026-07-15 13:59:33');

-- --------------------------------------------------------

--
-- Table structure for table `pod_poll_options`
--

CREATE TABLE `pod_poll_options` (
  `id` int(11) NOT NULL,
  `discussion_id` int(11) NOT NULL,
  `option_text` varchar(200) NOT NULL,
  `is_correct` tinyint(1) NOT NULL DEFAULT 0,
  `votes` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `pod_poll_options`
--

INSERT INTO `pod_poll_options` (`id`, `discussion_id`, `option_text`, `is_correct`, `votes`) VALUES
(1, 7, 'yeh', 0, 1),
(2, 7, 'Nah', 0, 1),
(9, 10, 'Belcalis', 1, 2),
(10, 10, 'Karla', 0, 0),
(11, 10, 'Bianca', 0, 0),
(12, 10, 'Camila', 0, 0),
(13, 13, 'Funny Audios', 0, 1),
(14, 13, 'Funny Images/Videos/GIFs', 0, 2);

-- --------------------------------------------------------

--
-- Table structure for table `pod_poll_votes`
--

CREATE TABLE `pod_poll_votes` (
  `id` int(11) NOT NULL,
  `discussion_id` int(11) NOT NULL,
  `option_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `pod_poll_votes`
--

INSERT INTO `pod_poll_votes` (`id`, `discussion_id`, `option_id`, `user_id`) VALUES
(1, 7, 1, 2),
(2, 7, 2, 7),
(5, 10, 9, 2),
(6, 10, 9, 7),
(7, 13, 14, 2),
(8, 13, 13, 4),
(9, 13, 14, 3);

-- --------------------------------------------------------

--
-- Table structure for table `pod_reactions`
--

CREATE TABLE `pod_reactions` (
  `id` int(11) NOT NULL,
  `discussion_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `emoji` varchar(10) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pod_reports`
--

CREATE TABLE `pod_reports` (
  `id` int(10) UNSIGNED NOT NULL,
  `reporter_id` int(10) UNSIGNED NOT NULL,
  `group_id` int(10) UNSIGNED NOT NULL,
  `reason` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('pending','under_review','resolved','dismissed') NOT NULL DEFAULT 'pending',
  `admin_notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `pod_reports`
--

INSERT INTO `pod_reports` (`id`, `reporter_id`, `group_id`, `reason`, `description`, `status`, `admin_notes`, `created_at`, `updated_at`) VALUES
(1, 3, 4, 'Harassment or Bullying', NULL, 'pending', NULL, '2026-06-23 08:51:03', '2026-06-23 08:51:03');

-- --------------------------------------------------------

--
-- Table structure for table `profile_views`
--

CREATE TABLE `profile_views` (
  `id` int(10) UNSIGNED NOT NULL,
  `viewer_id` int(10) UNSIGNED NOT NULL,
  `profile_id` int(10) UNSIGNED NOT NULL,
  `viewed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `seen` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `profile_views`
--

INSERT INTO `profile_views` (`id`, `viewer_id`, `profile_id`, `viewed_at`, `seen`) VALUES
(1, 2, 3, '2026-04-03 13:51:15', 0),
(2, 3, 2, '2026-04-03 13:52:13', 0),
(3, 1, 2, '2026-04-03 14:31:12', 0),
(4, 4, 2, '2026-04-03 14:31:35', 0),
(5, 4, 1, '2026-04-03 14:31:39', 0),
(6, 4, 5, '2026-04-03 14:31:52', 0),
(7, 5, 2, '2026-04-03 14:32:41', 0),
(8, 2, 4, '2026-04-03 15:57:41', 0),
(9, 2, 1, '2026-04-03 15:57:47', 0),
(10, 2, 5, '2026-04-03 18:26:58', 0),
(11, 1, 4, '2026-04-03 19:26:20', 0),
(12, 1, 5, '2026-04-04 03:07:37', 0),
(13, 1, 3, '2026-04-04 03:16:13', 0),
(14, 4, 3, '2026-04-04 11:16:07', 0),
(15, 5, 1, '2026-04-04 11:17:46', 0),
(16, 3, 5, '2026-04-04 18:49:01', 0),
(17, 3, 4, '2026-04-04 18:57:20', 0),
(18, 3, 1, '2026-04-04 19:36:33', 0),
(19, 5, 3, '2026-04-06 00:46:54', 0),
(20, 5, 4, '2026-04-06 00:49:54', 0),
(21, 5, 3, '2026-04-06 07:17:57', 0),
(22, 5, 2, '2026-04-06 07:18:02', 0),
(23, 2, 5, '2026-04-06 07:18:45', 0),
(24, 2, 5, '2026-04-06 10:39:42', 0),
(25, 2, 4, '2026-04-06 10:44:43', 0),
(26, 1, 4, '2026-04-06 11:02:01', 0),
(27, 1, 5, '2026-04-06 11:46:14', 0),
(28, 2, 1, '2026-04-06 11:47:25', 0),
(29, 1, 3, '2026-04-06 11:48:09', 0),
(30, 1, 4, '2026-04-06 12:36:39', 0),
(31, 1, 4, '2026-04-06 15:46:45', 0),
(32, 1, 2, '2026-04-06 15:46:53', 0),
(33, 1, 5, '2026-04-06 17:22:13', 0),
(34, 1, 4, '2026-04-06 17:39:30', 0),
(35, 1, 3, '2026-04-06 17:47:01', 0),
(36, 3, 4, '2026-04-06 18:56:31', 0),
(37, 3, 4, '2026-04-07 00:20:37', 0),
(38, 3, 1, '2026-04-07 00:32:43', 0),
(39, 3, 4, '2026-04-07 03:48:38', 0),
(40, 3, 1, '2026-04-07 04:21:00', 0),
(41, 3, 5, '2026-04-07 04:41:27', 0),
(42, 3, 4, '2026-04-07 05:31:32', 0),
(43, 3, 1, '2026-04-07 05:33:56', 0),
(44, 3, 2, '2026-04-07 05:38:20', 0),
(45, 3, 1, '2026-04-07 08:45:13', 0),
(46, 3, 4, '2026-04-07 08:48:30', 0),
(47, 3, 1, '2026-04-07 10:32:41', 0),
(48, 2, 1, '2026-04-07 16:15:32', 0),
(49, 2, 4, '2026-04-07 23:06:19', 0),
(50, 2, 5, '2026-04-07 23:09:08', 0),
(51, 2, 5, '2026-04-08 00:29:13', 0),
(52, 5, 3, '2026-04-08 00:57:21', 0),
(53, 1, 3, '2026-04-08 01:04:23', 0),
(54, 1, 2, '2026-04-08 01:09:26', 0),
(55, 1, 5, '2026-04-08 01:09:39', 0),
(56, 5, 2, '2026-04-08 01:16:23', 0),
(57, 5, 4, '2026-04-08 01:28:21', 0),
(58, 3, 5, '2026-04-08 01:39:12', 0),
(59, 3, 4, '2026-04-08 01:39:55', 0),
(60, 3, 1, '2026-04-08 01:42:04', 0),
(61, 2, 3, '2026-04-08 01:47:06', 0),
(62, 2, 4, '2026-04-08 01:47:11', 0),
(63, 3, 2, '2026-04-08 01:47:39', 0),
(64, 1, 4, '2026-04-08 01:50:25', 0),
(65, 1, 2, '2026-04-08 02:09:38', 0),
(66, 1, 5, '2026-04-08 02:29:01', 0),
(67, 1, 3, '2026-04-08 02:38:03', 0),
(68, 1, 4, '2026-04-08 04:01:43', 0),
(69, 1, 4, '2026-04-08 06:00:06', 0),
(70, 1, 2, '2026-04-08 06:00:09', 0),
(71, 1, 5, '2026-04-08 08:31:07', 0),
(72, 1, 2, '2026-04-08 08:31:11', 0),
(73, 1, 4, '2026-04-08 09:08:19', 0),
(74, 1, 2, '2026-04-08 09:43:31', 0),
(75, 1, 3, '2026-04-08 09:52:19', 0),
(76, 1, 5, '2026-04-09 03:08:18', 0),
(77, 1, 2, '2026-04-09 03:08:23', 0),
(78, 1, 3, '2026-04-09 03:30:26', 0),
(79, 1, 4, '2026-04-09 03:39:42', 0),
(80, 1, 5, '2026-04-09 04:26:07', 0),
(81, 1, 2, '2026-04-09 04:26:19', 0),
(82, 1, 3, '2026-04-09 04:32:46', 0),
(83, 7, 1, '2026-04-09 04:53:36', 0),
(84, 7, 2, '2026-04-09 04:54:00', 0),
(85, 2, 7, '2026-04-09 05:03:07', 0),
(86, 7, 3, '2026-04-09 05:22:31', 0),
(87, 4, 1, '2026-04-09 05:51:55', 0),
(88, 4, 7, '2026-04-09 05:54:10', 0),
(89, 4, 5, '2026-04-09 05:55:35', 0),
(90, 4, 2, '2026-04-09 05:55:41', 0),
(91, 4, 3, '2026-04-09 06:07:05', 0),
(92, 3, 7, '2026-04-09 06:21:02', 0),
(93, 7, 1, '2026-04-09 06:54:33', 0),
(94, 7, 3, '2026-04-09 06:56:17', 0),
(95, 7, 5, '2026-04-09 06:58:00', 0),
(96, 7, 2, '2026-04-09 07:07:57', 0),
(97, 7, 1, '2026-04-09 08:26:36', 0),
(98, 7, 3, '2026-04-09 08:57:46', 0),
(99, 7, 4, '2026-04-09 09:08:36', 0),
(100, 7, 5, '2026-04-09 09:23:48', 0),
(101, 7, 3, '2026-04-09 10:06:30', 0),
(102, 7, 1, '2026-04-09 10:10:09', 0),
(103, 7, 2, '2026-04-09 10:18:33', 0),
(104, 4, 7, '2026-04-09 11:57:24', 0),
(105, 4, 1, '2026-04-09 12:10:22', 0),
(106, 4, 7, '2026-04-10 05:22:15', 0),
(107, 4, 2, '2026-04-10 05:22:18', 0),
(108, 4, 5, '2026-04-10 05:22:53', 0),
(109, 4, 3, '2026-04-10 05:25:52', 0),
(110, 4, 1, '2026-04-10 05:26:03', 0),
(111, 2, 3, '2026-04-10 20:48:01', 0),
(112, 2, 4, '2026-04-10 20:48:10', 0),
(113, 2, 5, '2026-04-10 20:48:25', 0),
(114, 2, 7, '2026-04-10 20:48:41', 0),
(115, 2, 7, '2026-04-11 05:42:05', 0),
(116, 2, 4, '2026-04-11 06:06:59', 0),
(117, 2, 3, '2026-04-11 06:07:04', 0),
(118, 2, 4, '2026-04-11 13:22:21', 0),
(119, 2, 5, '2026-04-11 13:22:28', 0),
(120, 2, 1, '2026-04-11 13:22:36', 0),
(121, 2, 7, '2026-04-11 13:34:57', 0),
(122, 2, 4, '2026-04-11 14:22:41', 0),
(123, 2, 3, '2026-04-11 14:22:49', 0),
(124, 7, 2, '2026-04-11 15:03:13', 0),
(125, 4, 2, '2026-04-11 15:03:41', 0),
(126, 4, 3, '2026-04-11 15:10:47', 0),
(127, 4, 5, '2026-04-11 15:11:02', 0),
(128, 4, 1, '2026-04-11 15:12:53', 0),
(129, 4, 7, '2026-04-11 15:13:55', 0),
(130, 4, 2, '2026-04-11 16:05:36', 0),
(131, 1, 5, '2026-04-11 16:21:40', 0),
(132, 1, 4, '2026-04-11 16:24:14', 0),
(133, 1, 7, '2026-04-11 16:24:51', 0),
(134, 3, 4, '2026-04-11 16:33:35', 0),
(135, 3, 7, '2026-04-11 16:46:44', 0),
(136, 3, 1, '2026-04-11 16:56:13', 0),
(137, 3, 2, '2026-04-11 16:58:00', 0),
(138, 3, 5, '2026-04-11 17:03:17', 0),
(139, 5, 2, '2026-04-11 17:07:09', 0),
(140, 2, 7, '2026-04-12 06:10:26', 0),
(141, 1, 7, '2026-04-12 06:23:09', 0),
(142, 1, 3, '2026-04-18 07:14:30', 0),
(143, 7, 4, '2026-04-18 07:28:16', 0),
(144, 7, 3, '2026-04-18 07:28:47', 0),
(145, 7, 1, '2026-04-18 07:34:30', 0),
(146, 7, 2, '2026-04-18 07:37:28', 0),
(147, 3, 1, '2026-04-18 08:09:17', 0),
(148, 3, 4, '2026-04-18 08:52:47', 0),
(149, 3, 7, '2026-04-19 03:54:29', 0),
(150, 3, 2, '2026-04-19 03:54:42', 0),
(151, 3, 4, '2026-04-19 04:32:38', 0),
(152, 3, 1, '2026-04-19 04:44:20', 0),
(153, 3, 7, '2026-04-19 04:55:53', 0),
(154, 3, 4, '2026-04-19 08:07:34', 0),
(155, 3, 7, '2026-04-19 08:15:59', 0),
(156, 3, 5, '2026-04-19 17:00:05', 0),
(157, 3, 4, '2026-04-19 17:07:26', 0),
(158, 2, 1, '2026-04-20 03:33:09', 0),
(159, 2, 1, '2026-04-20 04:33:41', 0),
(160, 2, 5, '2026-04-20 06:01:20', 0),
(161, 2, 3, '2026-04-20 06:01:31', 0),
(162, 1, 7, '2026-04-20 08:13:53', 0),
(163, 3, 8, '2026-04-20 12:41:52', 0),
(164, 3, 1, '2026-04-20 13:04:08', 0),
(165, 3, 2, '2026-04-20 13:04:20', 0),
(166, 8, 3, '2026-04-20 13:51:47', 0),
(167, 8, 7, '2026-04-20 13:52:24', 0),
(168, 8, 4, '2026-04-20 13:52:32', 0),
(169, 3, 8, '2026-04-20 14:07:28', 0),
(170, 8, 3, '2026-04-20 16:40:57', 0),
(171, 8, 1, '2026-04-20 16:42:25', 0),
(172, 8, 4, '2026-04-20 17:22:31', 0),
(173, 8, 4, '2026-04-20 18:36:54', 0),
(174, 3, 8, '2026-04-21 05:04:33', 0),
(175, 7, 8, '2026-04-21 15:05:12', 0),
(176, 7, 1, '2026-04-21 16:53:10', 0),
(177, 7, 5, '2026-04-21 16:53:29', 0),
(178, 7, 8, '2026-04-21 17:10:55', 0),
(179, 7, 8, '2026-04-22 04:31:12', 0),
(180, 1, 8, '2026-04-23 06:05:09', 0),
(181, 1, 3, '2026-04-23 06:44:04', 0),
(182, 1, 2, '2026-04-23 07:45:41', 0),
(183, 1, 3, '2026-04-23 08:48:44', 0),
(184, 1, 8, '2026-04-23 08:49:05', 0),
(185, 1, 4, '2026-04-23 10:20:06', 0),
(186, 3, 2, '2026-04-23 19:05:52', 0),
(187, 3, 2, '2026-04-25 08:11:07', 0),
(188, 3, 8, '2026-04-25 08:29:29', 0),
(189, 3, 8, '2026-04-27 07:18:41', 0),
(190, 3, 2, '2026-04-27 07:29:17', 0),
(191, 2, 1, '2026-04-30 03:52:24', 0),
(192, 2, 3, '2026-04-30 03:54:44', 0),
(193, 2, 7, '2026-04-30 08:11:35', 0),
(194, 2, 8, '2026-05-03 07:36:18', 0),
(195, 2, 3, '2026-05-03 08:30:33', 0),
(196, 2, 8, '2026-05-03 08:46:37', 0),
(197, 2, 4, '2026-05-04 05:45:24', 0),
(198, 1, 5, '2026-05-04 06:21:34', 0),
(199, 2, 4, '2026-05-04 06:56:15', 0),
(200, 2, 8, '2026-05-04 06:59:35', 0),
(201, 2, 8, '2026-05-04 08:12:47', 0),
(202, 2, 4, '2026-05-05 08:09:48', 0),
(203, 1, 3, '2026-05-05 08:49:30', 0),
(204, 1, 3, '2026-05-05 11:09:41', 0),
(205, 8, 2, '2026-05-05 11:54:05', 0),
(206, 7, 2, '2026-05-05 14:27:37', 0),
(207, 1, 5, '2026-05-06 03:04:34', 0),
(208, 1, 3, '2026-05-06 03:21:14', 0),
(209, 1, 5, '2026-05-06 04:13:50', 0),
(210, 1, 3, '2026-05-06 04:50:10', 0),
(211, 1, 4, '2026-05-06 06:22:02', 0),
(212, 3, 5, '2026-05-06 07:07:49', 0),
(213, 3, 8, '2026-05-06 07:08:06', 0),
(214, 3, 4, '2026-05-06 08:07:49', 0),
(215, 3, 1, '2026-05-06 08:10:41', 0),
(216, 3, 5, '2026-05-06 08:17:05', 0),
(217, 3, 8, '2026-05-06 08:17:32', 0),
(218, 2, 7, '2026-05-06 08:43:57', 0),
(219, 3, 1, '2026-05-06 09:21:40', 0),
(220, 1, 4, '2026-05-06 10:20:49', 0),
(221, 7, 8, '2026-05-06 11:33:45', 0),
(222, 4, 5, '2026-05-07 02:51:59', 0),
(223, 4, 3, '2026-05-07 03:08:36', 0),
(224, 4, 1, '2026-05-07 03:21:58', 0),
(225, 4, 3, '2026-05-07 10:22:34', 0),
(226, 4, 5, '2026-05-07 10:25:44', 0),
(227, 4, 1, '2026-05-07 10:26:26', 0),
(228, 4, 8, '2026-05-07 10:27:05', 0),
(229, 4, 7, '2026-05-07 10:33:05', 0),
(230, 4, 2, '2026-05-07 10:35:36', 0),
(231, 4, 3, '2026-05-07 13:30:14', 0),
(232, 4, 8, '2026-05-07 13:36:37', 0),
(233, 4, 5, '2026-05-07 13:56:18', 0),
(234, 4, 2, '2026-05-07 13:56:31', 0),
(235, 5, 8, '2026-05-07 14:25:21', 0),
(236, 5, 3, '2026-05-07 14:32:31', 0),
(237, 5, 3, '2026-05-07 21:08:06', 0),
(238, 5, 8, '2026-05-07 21:27:53', 0),
(239, 5, 4, '2026-05-07 21:49:32', 0),
(240, 1, 5, '2026-05-07 21:52:02', 0),
(241, 1, 4, '2026-05-07 21:52:24', 0),
(242, 1, 8, '2026-05-07 21:56:47', 0),
(243, 1, 3, '2026-05-07 22:08:59', 0),
(244, 1, 3, '2026-05-08 03:59:56', 0),
(245, 1, 5, '2026-05-08 04:06:40', 0),
(246, 1, 8, '2026-05-08 04:19:23', 0),
(247, 1, 4, '2026-05-08 04:52:13', 0),
(248, 1, 3, '2026-05-08 05:04:08', 0),
(249, 1, 7, '2026-05-08 05:05:05', 0),
(250, 1, 5, '2026-05-08 05:13:46', 0),
(251, 1, 8, '2026-05-08 05:21:59', 0),
(252, 1, 3, '2026-05-08 06:05:29', 0),
(253, 1, 4, '2026-05-08 06:16:02', 0),
(254, 1, 7, '2026-05-08 06:20:19', 0),
(255, 1, 8, '2026-05-08 06:32:49', 0),
(256, 1, 2, '2026-05-08 06:53:46', 0),
(257, 1, 5, '2026-05-08 07:13:04', 0),
(258, 1, 3, '2026-05-08 07:15:15', 0),
(259, 1, 4, '2026-05-08 07:19:17', 0),
(260, 1, 8, '2026-05-08 07:36:28', 0),
(261, 7, 4, '2026-05-08 10:59:28', 0),
(262, 7, 8, '2026-05-08 11:06:15', 0),
(263, 7, 5, '2026-05-08 11:06:57', 0),
(264, 7, 1, '2026-05-08 11:32:59', 0),
(265, 7, 8, '2026-05-08 13:18:46', 0),
(266, 1, 3, '2026-05-08 13:46:59', 0),
(267, 1, 5, '2026-05-08 14:10:33', 0),
(268, 1, 8, '2026-05-08 14:10:41', 0),
(269, 1, 7, '2026-05-08 14:10:56', 0),
(270, 1, 2, '2026-05-08 14:19:24', 0),
(271, 1, 4, '2026-05-08 16:29:29', 0),
(272, 1, 3, '2026-05-08 16:34:20', 0),
(273, 8, 2, '2026-05-08 17:00:42', 0),
(274, 8, 7, '2026-05-08 17:01:42', 0),
(275, 8, 5, '2026-05-08 17:01:50', 0),
(276, 8, 4, '2026-05-08 17:01:54', 0),
(277, 8, 3, '2026-05-08 17:01:58', 0),
(278, 8, 1, '2026-05-08 17:02:20', 0),
(279, 1, 3, '2026-05-08 18:19:40', 0),
(280, 1, 5, '2026-05-09 04:33:48', 0),
(281, 8, 4, '2026-05-09 04:52:40', 0),
(282, 1, 8, '2026-05-09 04:56:05', 0),
(283, 1, 3, '2026-05-09 06:28:53', 0),
(284, 1, 4, '2026-05-09 07:07:56', 0),
(285, 1, 7, '2026-05-09 07:23:40', 0),
(286, 1, 5, '2026-05-09 07:33:56', 0),
(287, 1, 3, '2026-05-09 07:35:10', 0),
(288, 7, 2, '2026-05-09 07:51:15', 0),
(289, 7, 8, '2026-05-09 09:21:59', 0),
(290, 7, 1, '2026-05-09 09:23:22', 0),
(291, 7, 8, '2026-05-09 11:37:11', 0),
(292, 2, 3, '2026-05-09 14:20:51', 0),
(293, 2, 4, '2026-05-09 14:24:55', 0),
(294, 5, 4, '2026-05-09 16:11:21', 0),
(295, 5, 3, '2026-05-09 16:22:27', 0),
(296, 5, 2, '2026-05-09 16:22:31', 0),
(297, 8, 3, '2026-05-09 18:02:52', 0),
(298, 8, 3, '2026-05-09 19:10:00', 0),
(299, 8, 5, '2026-05-09 19:10:35', 0),
(300, 8, 1, '2026-05-09 20:15:55', 0),
(301, 8, 2, '2026-05-09 20:27:39', 0),
(302, 8, 4, '2026-05-09 21:06:14', 0),
(303, 4, 5, '2026-05-09 21:26:07', 0),
(304, 4, 2, '2026-05-09 21:26:14', 0),
(305, 4, 8, '2026-05-10 02:38:24', 0),
(306, 4, 3, '2026-05-10 02:39:05', 0),
(307, 4, 2, '2026-05-10 03:34:13', 0),
(308, 4, 5, '2026-05-10 03:35:16', 0),
(309, 4, 1, '2026-05-10 03:35:20', 0),
(310, 4, 7, '2026-05-10 03:35:48', 0),
(311, 4, 8, '2026-05-10 03:52:23', 0),
(312, 4, 2, '2026-05-10 04:37:54', 0),
(313, 4, 7, '2026-05-10 04:54:22', 0),
(314, 4, 5, '2026-05-10 05:13:17', 0),
(315, 4, 8, '2026-05-10 05:14:11', 0),
(316, 4, 8, '2026-05-10 06:18:04', 0),
(317, 8, 2, '2026-05-10 06:34:33', 0),
(318, 2, 3, '2026-05-10 06:47:03', 0),
(319, 3, 1, '2026-05-10 07:01:18', 0),
(320, 1, 3, '2026-05-10 07:06:19', 0),
(321, 1, 8, '2026-05-10 07:18:54', 0),
(322, 8, 7, '2026-05-10 07:19:19', 0),
(323, 8, 5, '2026-05-10 07:28:00', 0),
(324, 5, 3, '2026-05-10 08:21:37', 0),
(325, 3, 8, '2026-05-10 08:27:16', 0),
(326, 5, 8, '2026-05-10 08:47:51', 0),
(327, 5, 1, '2026-05-10 08:57:25', 0),
(328, 5, 7, '2026-05-10 09:04:01', 0),
(329, 4, 8, '2026-05-10 09:18:41', 0),
(330, 1, 8, '2026-05-10 09:19:37', 0),
(331, 2, 8, '2026-05-10 09:20:06', 0),
(332, 8, 2, '2026-05-10 09:54:10', 0),
(333, 8, 1, '2026-05-10 10:26:29', 0),
(334, 8, 7, '2026-05-10 10:27:35', 0),
(335, 8, 3, '2026-05-10 10:33:49', 0),
(336, 8, 1, '2026-05-10 15:09:11', 0),
(337, 8, 1, '2026-05-10 18:33:52', 0),
(338, 8, 7, '2026-05-10 18:47:05', 0),
(339, 8, 5, '2026-05-10 18:47:32', 0),
(340, 8, 2, '2026-05-10 18:47:43', 0),
(341, 8, 4, '2026-05-10 18:53:19', 0),
(342, 7, 2, '2026-05-10 20:27:22', 0),
(343, 1, 5, '2026-05-10 21:03:54', 0),
(344, 1, 2, '2026-05-10 21:08:52', 0),
(345, 1, 3, '2026-05-10 21:09:49', 0),
(346, 1, 4, '2026-05-10 21:14:15', 0),
(347, 1, 7, '2026-05-10 21:21:31', 0),
(348, 1, 8, '2026-05-10 21:35:19', 0),
(349, 1, 7, '2026-05-11 05:54:00', 0),
(350, 1, 8, '2026-05-11 05:54:15', 0),
(351, 1, 3, '2026-05-11 05:54:38', 0),
(352, 1, 3, '2026-05-11 07:30:58', 0),
(353, 1, 4, '2026-05-11 07:33:05', 0),
(354, 1, 5, '2026-05-11 07:42:03', 0),
(355, 1, 8, '2026-05-11 07:42:09', 0),
(356, 1, 2, '2026-05-11 07:46:16', 0),
(357, 1, 7, '2026-05-11 08:26:27', 0),
(358, 1, 4, '2026-05-11 08:35:12', 0),
(359, 1, 3, '2026-05-11 08:51:48', 0),
(360, 4, 1, '2026-05-11 09:15:22', 0),
(361, 2, 1, '2026-05-11 09:46:06', 0),
(362, 2, 1, '2026-05-11 11:16:57', 0),
(363, 2, 7, '2026-05-11 11:17:22', 0),
(364, 2, 3, '2026-05-11 13:32:04', 0),
(365, 2, 8, '2026-05-11 13:51:43', 0),
(366, 2, 3, '2026-05-11 15:14:46', 0),
(367, 2, 8, '2026-05-11 15:22:32', 0),
(368, 2, 1, '2026-05-11 16:10:36', 0),
(369, 2, 4, '2026-05-11 16:11:14', 0),
(370, 2, 5, '2026-05-11 16:11:20', 0),
(371, 2, 5, '2026-05-11 17:25:29', 0),
(372, 2, 3, '2026-05-11 17:34:34', 0),
(373, 2, 8, '2026-05-11 17:35:30', 0),
(374, 2, 7, '2026-05-11 17:35:42', 0),
(375, 2, 1, '2026-05-11 17:35:58', 0),
(376, 2, 1, '2026-05-11 18:37:13', 0),
(377, 2, 5, '2026-05-11 19:08:58', 0),
(378, 2, 7, '2026-05-11 19:09:05', 0),
(379, 2, 4, '2026-05-11 19:09:10', 0),
(380, 2, 1, '2026-05-11 19:51:51', 0),
(381, 2, 3, '2026-05-11 20:02:28', 0),
(382, 2, 7, '2026-05-11 20:32:09', 0),
(383, 2, 4, '2026-05-11 20:33:43', 0),
(384, 2, 5, '2026-05-11 20:34:03', 0),
(385, 2, 8, '2026-05-11 20:36:02', 0),
(386, 2, 1, '2026-05-11 21:05:22', 0),
(387, 2, 8, '2026-05-11 23:20:25', 0),
(388, 2, 5, '2026-05-12 06:48:27', 0),
(389, 1, 8, '2026-05-12 08:52:12', 0),
(390, 1, 3, '2026-05-12 09:08:43', 0),
(391, 1, 7, '2026-05-12 09:13:07', 0),
(392, 1, 4, '2026-05-12 09:35:48', 0),
(393, 3, 1, '2026-05-12 09:42:11', 0),
(394, 3, 8, '2026-05-12 09:58:35', 0),
(395, 1, 3, '2026-05-12 10:09:19', 0),
(396, 1, 3, '2026-05-12 11:24:18', 0),
(397, 1, 5, '2026-05-12 11:34:49', 0),
(398, 1, 7, '2026-05-12 11:44:38', 0),
(399, 5, 1, '2026-05-12 12:03:44', 0),
(400, 5, 3, '2026-05-12 12:05:29', 0),
(401, 5, 4, '2026-05-12 12:33:13', 0),
(402, 8, 5, '2026-05-12 15:27:27', 0),
(403, 8, 4, '2026-05-12 16:40:43', 0),
(404, 8, 2, '2026-05-12 17:21:49', 0),
(405, 1, 4, '2026-05-12 21:44:59', 0),
(406, 1, 3, '2026-05-12 21:46:19', 0),
(407, 1, 8, '2026-05-13 00:02:41', 0),
(408, 1, 8, '2026-05-13 02:14:39', 0),
(409, 1, 3, '2026-05-13 04:15:09', 0),
(410, 1, 7, '2026-05-13 04:22:41', 0),
(411, 1, 8, '2026-05-13 04:29:57', 0),
(412, 1, 2, '2026-05-13 05:04:28', 0),
(413, 1, 4, '2026-05-13 05:04:51', 0),
(414, 1, 5, '2026-05-13 12:46:47', 0),
(415, 1, 4, '2026-05-13 13:53:10', 0),
(416, 1, 8, '2026-05-13 14:28:29', 0),
(417, 1, 2, '2026-05-13 14:28:39', 0),
(418, 1, 4, '2026-05-13 15:10:25', 0),
(419, 1, 4, '2026-05-13 20:22:15', 0),
(420, 1, 3, '2026-05-13 20:24:27', 0),
(421, 1, 2, '2026-05-14 09:00:12', 0),
(422, 1, 4, '2026-05-14 09:03:34', 0),
(423, 1, 8, '2026-05-14 13:00:27', 0),
(424, 1, 4, '2026-05-14 17:07:08', 0),
(425, 1, 7, '2026-05-14 18:45:42', 0),
(426, 1, 3, '2026-05-14 18:45:59', 0),
(427, 1, 4, '2026-05-14 18:46:08', 0),
(428, 1, 3, '2026-05-14 21:09:58', 0),
(429, 3, 4, '2026-05-14 21:12:16', 0),
(430, 3, 2, '2026-05-14 21:12:37', 0),
(431, 3, 1, '2026-05-15 04:29:36', 0),
(432, 3, 5, '2026-05-15 04:30:55', 0),
(433, 3, 7, '2026-05-15 05:54:48', 0),
(434, 3, 8, '2026-05-15 05:55:27', 0),
(435, 3, 2, '2026-05-15 05:55:42', 0),
(436, 3, 8, '2026-05-15 08:36:00', 0),
(437, 1, 2, '2026-05-15 11:42:17', 0),
(438, 2, 1, '2026-05-15 12:26:42', 0),
(439, 3, 2, '2026-05-15 12:27:09', 0),
(440, 2, 3, '2026-05-15 12:29:01', 0),
(441, 2, 4, '2026-05-15 12:35:56', 0),
(442, 4, 1, '2026-05-15 12:57:43', 0),
(443, 4, 8, '2026-05-15 13:06:32', 0),
(444, 3, 1, '2026-05-15 13:38:32', 0),
(445, 3, 4, '2026-05-15 13:48:22', 0),
(446, 2, 3, '2026-05-15 16:18:51', 0),
(447, 5, 2, '2026-05-16 15:05:37', 0),
(448, 5, 3, '2026-05-16 15:32:16', 0),
(449, 5, 8, '2026-05-16 15:51:52', 0),
(450, 5, 1, '2026-05-16 15:51:56', 0),
(451, 5, 4, '2026-05-16 15:52:09', 0),
(452, 5, 7, '2026-05-16 15:52:19', 0),
(453, 3, 4, '2026-05-16 16:28:28', 0),
(454, 3, 5, '2026-05-16 16:31:19', 0),
(455, 8, 3, '2026-05-16 16:38:15', 0),
(456, 8, 4, '2026-05-16 16:38:27', 0),
(457, 8, 7, '2026-05-16 16:38:45', 0),
(458, 8, 2, '2026-05-16 16:40:42', 0),
(459, 8, 1, '2026-05-16 16:42:55', 0),
(460, 8, 5, '2026-05-16 17:14:21', 0),
(461, 5, 4, '2026-05-16 17:15:19', 0),
(462, 5, 8, '2026-05-16 17:19:16', 0),
(463, 5, 3, '2026-05-16 17:19:28', 0),
(464, 5, 7, '2026-05-16 17:21:30', 0),
(465, 7, 3, '2026-05-16 17:36:57', 0),
(466, 8, 1, '2026-05-16 17:43:49', 0),
(467, 8, 3, '2026-05-16 17:43:54', 0),
(468, 8, 7, '2026-05-16 17:53:36', 0),
(469, 8, 2, '2026-05-16 17:54:53', 0),
(470, 3, 8, '2026-05-16 18:18:01', 0),
(471, 3, 2, '2026-05-16 18:23:53', 0),
(472, 3, 7, '2026-05-16 18:24:19', 0),
(473, 3, 5, '2026-05-16 19:16:01', 0),
(474, 3, 4, '2026-05-16 19:16:14', 0),
(475, 3, 8, '2026-05-16 19:18:13', 0),
(476, 3, 7, '2026-05-16 19:52:18', 0),
(477, 3, 1, '2026-05-16 20:01:15', 0),
(478, 4, 3, '2026-05-16 20:02:40', 0),
(479, 4, 8, '2026-05-16 20:17:24', 0),
(480, 4, 7, '2026-05-16 20:31:51', 0),
(481, 4, 1, '2026-05-16 20:36:09', 0),
(482, 4, 2, '2026-05-16 20:36:20', 0),
(483, 4, 5, '2026-05-16 21:02:54', 0),
(484, 4, 7, '2026-05-16 22:49:43', 0),
(485, 4, 3, '2026-05-17 05:49:08', 0),
(486, 4, 1, '2026-05-17 05:49:12', 0),
(487, 4, 7, '2026-05-17 06:24:24', 0),
(488, 4, 5, '2026-05-17 06:25:49', 0),
(489, 4, 8, '2026-05-17 06:46:37', 0),
(490, 4, 2, '2026-05-17 07:19:19', 0),
(491, 4, 1, '2026-05-17 08:21:05', 0),
(492, 4, 3, '2026-05-17 08:21:22', 0),
(493, 4, 2, '2026-05-17 08:30:04', 0),
(494, 4, 7, '2026-05-17 08:38:12', 0),
(495, 4, 5, '2026-05-17 08:41:28', 0),
(496, 4, 8, '2026-05-17 08:43:14', 0),
(497, 4, 1, '2026-05-17 09:58:16', 0),
(498, 3, 1, '2026-05-17 12:08:39', 0),
(499, 8, 2, '2026-05-18 01:55:16', 0),
(500, 8, 4, '2026-05-18 01:55:42', 0),
(501, 8, 1, '2026-05-18 01:56:14', 0),
(502, 8, 5, '2026-05-18 01:56:33', 0),
(503, 8, 7, '2026-05-18 02:01:40', 0),
(504, 8, 3, '2026-05-18 02:05:36', 0),
(505, 8, 4, '2026-05-18 03:33:27', 0),
(506, 8, 3, '2026-05-18 03:57:58', 0),
(507, 8, 1, '2026-05-18 04:23:53', 0),
(508, 8, 3, '2026-05-18 05:44:03', 0),
(509, 8, 4, '2026-05-18 06:59:31', 0),
(510, 8, 7, '2026-05-18 06:59:37', 0),
(511, 8, 5, '2026-05-18 08:38:34', 0),
(512, 8, 4, '2026-05-18 08:38:44', 0),
(514, 3, 7, '2026-05-18 09:08:14', 0),
(515, 3, 8, '2026-05-18 09:09:30', 0),
(516, 2, 8, '2026-05-18 10:52:57', 0),
(517, 2, 4, '2026-05-18 10:55:06', 0),
(518, 2, 3, '2026-05-18 10:55:16', 0),
(519, 2, 7, '2026-05-18 10:55:55', 0),
(520, 2, 5, '2026-05-18 10:57:56', 0),
(521, 2, 1, '2026-05-18 10:58:11', 0),
(524, 5, 2, '2026-05-18 12:55:58', 0),
(525, 5, 8, '2026-05-18 12:56:08', 0),
(526, 5, 4, '2026-05-18 12:56:16', 0),
(527, 2, 1, '2026-05-18 13:43:59', 0),
(528, 4, 2, '2026-05-18 13:57:49', 0),
(529, 4, 5, '2026-05-18 13:57:58', 0),
(530, 4, 8, '2026-05-18 13:58:12', 0),
(531, 4, 1, '2026-05-18 13:58:30', 0),
(532, 1, 4, '2026-05-18 14:08:09', 0),
(533, 1, 2, '2026-05-18 14:11:27', 0),
(534, 1, 7, '2026-05-18 14:11:50', 0),
(535, 1, 5, '2026-05-18 14:19:45', 0),
(536, 1, 3, '2026-05-18 14:19:52', 0),
(537, 1, 8, '2026-05-18 14:20:01', 0),
(538, 1, 13, '2026-05-18 14:25:33', 0),
(539, 1, 7, '2026-05-18 15:15:22', 0),
(540, 1, 4, '2026-05-18 15:16:44', 0),
(541, 1, 3, '2026-05-18 15:33:57', 0),
(542, 1, 8, '2026-05-18 15:34:17', 0),
(543, 1, 2, '2026-05-18 15:35:52', 0),
(544, 1, 5, '2026-05-19 05:51:01', 0),
(545, 1, 7, '2026-05-19 05:51:58', 0),
(546, 2, 13, '2026-05-19 06:10:25', 0),
(547, 2, 3, '2026-05-19 06:23:31', 0),
(548, 2, 5, '2026-05-19 08:35:44', 0),
(549, 2, 4, '2026-05-19 08:35:51', 0),
(550, 4, 2, '2026-05-19 10:25:44', 0),
(551, 3, 2, '2026-05-19 11:27:24', 0),
(552, 3, 1, '2026-05-19 11:27:42', 0),
(553, 2, 1, '2026-05-19 11:34:18', 0),
(554, 1, 2, '2026-05-19 11:35:52', 0),
(555, 13, 2, '2026-05-19 12:36:40', 0),
(556, 13, 3, '2026-05-19 12:36:50', 0),
(557, 13, 4, '2026-05-19 12:37:15', 0),
(558, 7, 1, '2026-05-19 12:50:39', 0),
(559, 7, 3, '2026-05-19 13:05:27', 0),
(560, 5, 4, '2026-05-19 13:08:53', 0),
(561, 5, 2, '2026-05-19 13:08:59', 0),
(562, 5, 7, '2026-05-19 13:09:04', 0),
(563, 5, 1, '2026-05-19 13:12:56', 0),
(564, 5, 8, '2026-05-19 15:47:35', 0),
(565, 5, 1, '2026-05-19 15:47:53', 0),
(566, 5, 2, '2026-05-19 15:48:02', 0),
(567, 5, 4, '2026-05-19 15:48:17', 0),
(568, 5, 7, '2026-05-19 15:48:51', 0),
(569, 5, 3, '2026-05-19 15:54:21', 0),
(570, 1, 7, '2026-05-19 16:45:55', 0),
(571, 1, 8, '2026-05-19 16:46:06', 0),
(572, 1, 3, '2026-05-19 16:56:58', 0),
(573, 1, 5, '2026-05-19 16:57:11', 0),
(574, 1, 13, '2026-05-19 16:57:18', 0),
(575, 1, 4, '2026-05-19 16:57:52', 0),
(576, 4, 7, '2026-05-19 17:33:12', 0),
(577, 4, 8, '2026-05-19 17:33:34', 0),
(578, 4, 1, '2026-05-19 17:34:31', 0),
(579, 4, 3, '2026-05-19 17:45:31', 0),
(580, 4, 2, '2026-05-19 17:45:35', 0),
(581, 4, 5, '2026-05-19 17:45:41', 0),
(582, 4, 13, '2026-05-19 17:48:16', 0),
(583, 2, 8, '2026-05-20 06:54:25', 0),
(584, 2, 4, '2026-05-20 06:55:05', 0),
(585, 2, 13, '2026-05-20 06:55:14', 0),
(586, 2, 1, '2026-05-20 06:55:24', 0),
(587, 2, 3, '2026-05-20 06:55:36', 0),
(588, 1, 3, '2026-05-20 10:00:09', 0),
(589, 1, 7, '2026-05-20 11:36:29', 0),
(590, 1, 8, '2026-05-20 11:40:30', 0),
(591, 1, 4, '2026-05-20 11:41:29', 0),
(592, 1, 3, '2026-05-20 11:41:52', 0),
(593, 1, 7, '2026-05-20 13:43:20', 0),
(594, 1, 13, '2026-05-20 13:43:30', 0),
(595, 1, 4, '2026-05-20 13:43:37', 0),
(596, 1, 8, '2026-05-20 13:44:29', 0),
(597, 2, 1, '2026-05-20 13:53:29', 0),
(598, 2, 5, '2026-05-20 13:56:26', 0),
(599, 2, 3, '2026-05-20 13:59:30', 0),
(600, 2, 8, '2026-05-20 14:04:29', 0),
(601, 2, 13, '2026-05-20 14:09:08', 0),
(602, 2, 7, '2026-05-20 14:11:40', 0),
(603, 2, 1, '2026-05-20 15:37:50', 0),
(604, 2, 5, '2026-05-20 15:39:02', 0),
(605, 2, 3, '2026-05-20 15:41:09', 0),
(606, 2, 8, '2026-05-20 15:43:17', 0),
(607, 2, 3, '2026-05-21 08:42:59', 0),
(610, 2, 8, '2026-05-21 09:07:38', 0),
(611, 2, 3, '2026-05-21 09:45:04', 0),
(612, 4, 2, '2026-05-21 10:31:19', 0),
(613, 4, 8, '2026-05-21 11:03:04', 0),
(614, 1, 7, '2026-05-21 11:29:40', 0),
(615, 1, 2, '2026-05-21 11:42:58', 0),
(616, 1, 4, '2026-05-21 11:49:38', 0),
(617, 1, 5, '2026-05-21 11:49:49', 0),
(618, 3, 1, '2026-05-21 11:53:41', 0),
(619, 3, 8, '2026-05-21 11:54:23', 0),
(620, 3, 2, '2026-05-21 12:06:55', 0),
(621, 4, 3, '2026-05-21 12:09:50', 0),
(622, 8, 3, '2026-05-21 14:39:31', 0),
(623, 8, 4, '2026-05-21 15:38:34', 0),
(624, 3, 8, '2026-05-21 15:44:13', 0),
(625, 3, 4, '2026-05-21 16:20:18', 0),
(626, 3, 2, '2026-05-21 16:27:30', 0),
(627, 3, 5, '2026-05-21 16:32:09', 0),
(628, 1, 8, '2026-05-21 16:33:00', 0),
(629, 1, 3, '2026-05-21 16:33:12', 0),
(630, 4, 1, '2026-05-21 16:59:04', 0),
(631, 4, 8, '2026-05-21 17:08:00', 0),
(632, 3, 4, '2026-05-21 17:38:23', 0),
(633, 4, 3, '2026-05-21 18:57:35', 0),
(634, 8, 4, '2026-05-21 18:58:56', 0),
(635, 8, 2, '2026-05-21 19:05:14', 0),
(636, 8, 1, '2026-05-21 19:06:15', 0),
(637, 8, 3, '2026-05-21 19:06:22', 0),
(638, 8, 3, '2026-05-21 21:36:36', 0),
(639, 8, 1, '2026-05-21 21:36:47', 0),
(640, 8, 2, '2026-05-22 06:33:24', 0),
(641, 8, 3, '2026-05-22 06:40:42', 0),
(642, 2, 8, '2026-05-22 06:41:40', 0),
(643, 4, 8, '2026-05-22 07:03:40', 0),
(644, 4, 3, '2026-05-22 07:03:47', 0),
(645, 4, 2, '2026-05-22 07:03:55', 0),
(646, 4, 3, '2026-05-22 08:20:43', 0),
(647, 3, 1, '2026-05-22 10:24:20', 0),
(648, 3, 1, '2026-05-22 14:05:04', 0),
(649, 3, 2, '2026-05-22 14:11:44', 0),
(650, 3, 4, '2026-05-22 14:16:51', 0),
(651, 3, 5, '2026-05-22 14:20:57', 0),
(652, 3, 2, '2026-05-22 16:07:15', 0),
(653, 3, 1, '2026-05-22 16:12:27', 0),
(654, 3, 8, '2026-05-22 16:13:20', 0),
(655, 3, 4, '2026-05-22 16:13:40', 0),
(656, 3, 13, '2026-05-22 20:36:03', 0),
(657, 3, 1, '2026-05-22 20:48:36', 0),
(658, 3, 4, '2026-05-22 20:52:39', 0),
(659, 3, 2, '2026-05-22 21:18:35', 0),
(660, 3, 5, '2026-05-22 21:18:45', 0),
(661, 1, 4, '2026-05-22 21:46:40', 0),
(662, 1, 13, '2026-05-22 21:48:16', 0),
(663, 4, 1, '2026-05-23 06:05:51', 0),
(664, 4, 8, '2026-05-23 06:06:09', 0),
(665, 4, 3, '2026-05-23 06:06:19', 0),
(666, 4, 8, '2026-05-23 07:19:58', 0),
(667, 4, 1, '2026-05-23 07:32:55', 0),
(668, 4, 3, '2026-05-23 07:35:56', 0),
(669, 2, 4, '2026-05-23 08:38:58', 0),
(670, 2, 7, '2026-05-23 08:39:47', 0),
(671, 2, 8, '2026-05-23 10:30:59', 0),
(672, 2, 4, '2026-05-23 11:29:23', 0),
(673, 2, 1, '2026-05-23 11:30:31', 0),
(678, 1, 8, '2026-05-23 18:02:53', 0),
(679, 1, 4, '2026-05-23 18:40:02', 0),
(680, 1, 3, '2026-05-24 01:41:33', 0),
(681, 1, 4, '2026-05-24 02:28:27', 0),
(682, 1, 3, '2026-05-24 02:56:02', 0),
(683, 1, 5, '2026-05-24 03:39:51', 0),
(684, 1, 3, '2026-05-24 04:29:23', 0),
(685, 1, 2, '2026-05-25 09:14:46', 0),
(686, 1, 3, '2026-05-25 09:14:54', 0),
(687, 3, 1, '2026-05-28 04:04:40', 0),
(688, 3, 4, '2026-05-28 04:10:06', 0),
(689, 3, 8, '2026-05-28 04:32:15', 0),
(690, 3, 7, '2026-05-28 04:43:09', 0),
(691, 3, 4, '2026-05-28 08:08:00', 0),
(692, 3, 1, '2026-05-28 08:08:09', 0),
(693, 3, 7, '2026-05-28 09:04:05', 0),
(694, 2, 3, '2026-05-28 10:56:13', 0),
(695, 3, 4, '2026-05-28 11:05:51', 0),
(696, 3, 1, '2026-05-28 11:07:28', 0),
(697, 3, 8, '2026-05-28 13:34:34', 0),
(698, 1, 3, '2026-05-28 13:37:02', 0),
(699, 3, 1, '2026-05-28 13:39:40', 0),
(700, 3, 4, '2026-05-28 13:40:13', 0),
(701, 3, 5, '2026-05-28 13:49:30', 0),
(702, 2, 3, '2026-05-28 14:42:05', 0),
(703, 3, 4, '2026-05-28 14:45:56', 0),
(704, 3, 8, '2026-05-28 14:47:32', 0),
(705, 8, 3, '2026-05-28 14:58:41', 0),
(706, 4, 8, '2026-05-28 15:11:03', 0),
(707, 3, 1, '2026-05-28 15:20:22', 0),
(708, 3, 4, '2026-05-28 16:12:33', 0),
(709, 3, 2, '2026-05-28 16:28:59', 0),
(710, 3, 8, '2026-05-28 16:29:57', 0),
(711, 3, 4, '2026-05-28 21:52:23', 0),
(712, 4, 3, '2026-05-28 21:53:10', 0),
(713, 3, 4, '2026-05-29 06:58:39', 0),
(714, 3, 7, '2026-05-29 07:01:16', 0),
(715, 4, 3, '2026-05-29 07:14:24', 0),
(716, 4, 1, '2026-05-29 07:14:47', 0),
(717, 3, 8, '2026-05-29 08:24:29', 0),
(718, 3, 4, '2026-05-29 08:51:50', 0),
(719, 4, 3, '2026-05-29 10:36:41', 0),
(720, 4, 2, '2026-05-29 10:45:21', 0),
(721, 3, 4, '2026-05-29 11:16:47', 0),
(722, 4, 3, '2026-05-29 11:57:17', 0),
(723, 4, 7, '2026-05-29 12:25:33', 0),
(724, 4, 2, '2026-05-29 12:30:46', 0),
(725, 4, 1, '2026-05-29 12:35:50', 0),
(726, 8, 4, '2026-05-29 12:43:14', 0),
(727, 8, 3, '2026-05-29 12:43:24', 0),
(728, 4, 7, '2026-05-29 13:50:26', 0),
(729, 1, 3, '2026-05-29 13:51:20', 0),
(730, 1, 3, '2026-05-29 15:13:27', 0),
(731, 5, 3, '2026-05-29 15:43:55', 0),
(732, 5, 3, '2026-05-29 16:57:33', 0),
(733, 7, 3, '2026-05-30 04:37:30', 0),
(734, 7, 2, '2026-05-30 04:41:24', 0),
(735, 7, 4, '2026-05-30 04:41:36', 0),
(736, 7, 3, '2026-05-30 06:09:54', 0),
(737, 7, 2, '2026-05-30 06:10:55', 0),
(738, 7, 4, '2026-05-30 06:11:09', 0),
(739, 3, 7, '2026-05-30 06:18:52', 0),
(740, 1, 3, '2026-05-30 06:22:00', 0),
(741, 1, 2, '2026-05-30 06:23:27', 0),
(742, 1, 7, '2026-05-30 06:23:44', 0),
(743, 7, 8, '2026-05-30 08:56:29', 0),
(744, 7, 1, '2026-05-30 08:56:39', 0),
(745, 4, 1, '2026-05-30 11:11:31', 0),
(746, 4, 7, '2026-05-30 11:12:16', 0),
(747, 4, 3, '2026-05-30 11:48:41', 0),
(748, 13, 3, '2026-05-30 11:51:30', 0),
(749, 2, 4, '2026-05-30 11:57:02', 0),
(750, 2, 1, '2026-05-30 12:25:30', 0),
(751, 2, 13, '2026-05-30 12:25:54', 0),
(752, 2, 5, '2026-05-30 12:38:31', 0),
(753, 2, 3, '2026-05-30 18:29:03', 0),
(754, 2, 13, '2026-05-31 04:15:01', 0),
(755, 2, 5, '2026-05-31 04:22:07', 0),
(756, 2, 8, '2026-05-31 04:34:16', 0),
(757, 2, 3, '2026-05-31 04:39:45', 0),
(758, 13, 3, '2026-05-31 04:40:24', 0),
(759, 13, 7, '2026-05-31 04:59:45', 0),
(760, 13, 1, '2026-05-31 05:05:58', 0),
(761, 2, 4, '2026-06-01 09:32:38', 0),
(762, 13, 1, '2026-06-01 10:12:04', 0),
(763, 13, 7, '2026-06-01 10:13:16', 0),
(764, 1, 2, '2026-06-01 20:54:09', 0),
(765, 1, 3, '2026-06-01 20:54:20', 0),
(766, 1, 3, '2026-06-01 21:54:25', 0),
(767, 3, 7, '2026-06-01 22:36:20', 0),
(768, 3, 2, '2026-06-01 23:04:24', 0),
(769, 3, 4, '2026-06-01 23:07:11', 0),
(770, 1, 3, '2026-06-01 23:13:17', 0),
(771, 1, 4, '2026-06-01 23:13:31', 0),
(772, 1, 4, '2026-06-02 11:10:13', 0),
(773, 1, 7, '2026-06-02 11:21:26', 0),
(774, 2, 3, '2026-06-02 18:35:40', 0),
(775, 2, 3, '2026-06-03 06:04:52', 0),
(776, 2, 8, '2026-06-03 06:05:18', 0),
(777, 3, 2, '2026-06-03 06:26:20', 0),
(778, 4, 8, '2026-06-03 07:52:44', 0),
(779, 8, 1, '2026-06-03 09:00:52', 0),
(780, 8, 13, '2026-06-03 09:00:58', 0),
(781, 8, 3, '2026-06-03 09:01:18', 0),
(782, 2, 8, '2026-06-03 09:19:02', 0),
(783, 3, 4, '2026-06-04 06:49:40', 0),
(784, 3, 8, '2026-06-04 14:20:45', 0),
(785, 3, 5, '2026-06-04 14:23:59', 0),
(786, 3, 13, '2026-06-04 14:37:40', 0),
(787, 3, 1, '2026-06-04 14:56:45', 0),
(788, 3, 7, '2026-06-04 15:42:55', 0),
(789, 3, 2, '2026-06-04 16:33:57', 0),
(790, 3, 8, '2026-06-04 16:45:46', 0),
(791, 3, 1, '2026-06-04 17:25:26', 0),
(792, 3, 1, '2026-06-04 18:46:46', 0),
(793, 3, 5, '2026-06-04 18:51:01', 0),
(794, 3, 13, '2026-06-04 19:20:24', 0),
(795, 3, 13, '2026-06-04 20:22:04', 0),
(796, 3, 4, '2026-06-04 20:29:40', 0),
(797, 3, 2, '2026-06-04 20:40:52', 0),
(798, 3, 8, '2026-06-04 20:50:24', 0),
(799, 3, 1, '2026-06-04 20:51:16', 0),
(800, 3, 1, '2026-06-05 05:17:50', 0),
(801, 3, 8, '2026-06-05 05:27:24', 0),
(802, 3, 5, '2026-06-05 05:28:57', 0),
(803, 3, 7, '2026-06-05 05:30:13', 0),
(804, 3, 2, '2026-06-05 05:36:07', 0),
(805, 2, 3, '2026-06-05 05:37:57', 0),
(806, 13, 3, '2026-06-05 05:39:00', 0),
(807, 13, 2, '2026-06-05 05:40:47', 0),
(808, 2, 1, '2026-06-05 05:49:53', 0),
(809, 2, 4, '2026-06-05 05:55:49', 0),
(810, 2, 8, '2026-06-05 06:00:27', 0),
(811, 2, 4, '2026-06-05 07:03:02', 0),
(812, 2, 5, '2026-06-05 07:06:39', 0),
(813, 2, 3, '2026-06-05 07:06:49', 0),
(814, 2, 1, '2026-06-05 07:06:56', 0),
(815, 2, 8, '2026-06-05 07:07:00', 0),
(816, 1, 13, '2026-06-05 07:29:08', 0),
(817, 1, 4, '2026-06-05 07:30:54', 0),
(818, 1, 2, '2026-06-05 07:36:59', 0),
(819, 1, 3, '2026-06-05 09:34:46', 0),
(820, 1, 3, '2026-06-05 10:49:29', 0),
(821, 2, 7, '2026-06-05 10:56:42', 0),
(822, 2, 4, '2026-06-05 11:10:09', 0),
(823, 7, 4, '2026-06-05 11:33:39', 0),
(824, 7, 2, '2026-06-05 11:33:52', 0),
(825, 7, 1, '2026-06-05 11:34:07', 0),
(826, 13, 8, '2026-06-05 11:42:54', 0),
(827, 7, 8, '2026-06-05 16:17:07', 0),
(828, 3, 1, '2026-06-05 16:17:24', 0),
(829, 13, 1, '2026-06-05 16:22:39', 0),
(830, 2, 3, '2026-06-05 16:23:37', 0),
(831, 2, 7, '2026-06-05 16:25:32', 0),
(832, 7, 1, '2026-06-05 16:36:11', 0),
(833, 7, 4, '2026-06-05 19:36:44', 0),
(834, 7, 4, '2026-06-06 05:02:04', 0),
(835, 7, 3, '2026-06-06 05:42:56', 0),
(836, 7, 1, '2026-06-06 05:47:54', 0),
(837, 7, 3, '2026-06-06 06:55:33', 0),
(838, 13, 2, '2026-06-06 07:28:58', 0),
(839, 2, 13, '2026-06-06 10:36:27', 0),
(840, 13, 2, '2026-06-06 10:45:58', 0),
(841, 2, 3, '2026-06-06 10:46:16', 0),
(842, 2, 5, '2026-06-06 10:54:08', 0),
(843, 13, 2, '2026-06-07 07:57:57', 0),
(844, 2, 7, '2026-06-07 07:58:10', 0),
(845, 2, 8, '2026-06-07 08:03:26', 0),
(846, 2, 4, '2026-06-07 08:26:03', 0),
(847, 2, 13, '2026-06-07 09:07:52', 0),
(848, 2, 7, '2026-06-07 09:46:20', 0),
(849, 3, 7, '2026-06-07 12:45:16', 0),
(850, 3, 1, '2026-06-07 13:17:17', 0),
(851, 3, 8, '2026-06-07 13:18:06', 0),
(852, 3, 4, '2026-06-07 13:18:41', 0),
(853, 2, 7, '2026-06-07 18:27:23', 0),
(854, 2, 5, '2026-06-07 18:30:50', 0),
(855, 2, 8, '2026-06-07 18:52:07', 0),
(856, 2, 3, '2026-06-07 18:54:34', 0),
(857, 2, 4, '2026-06-07 18:57:20', 0),
(858, 3, 2, '2026-06-07 19:06:32', 0),
(859, 3, 4, '2026-06-07 19:20:13', 0),
(860, 3, 8, '2026-06-07 19:26:50', 0),
(861, 3, 1, '2026-06-07 20:39:00', 0),
(867, 13, 8, '2026-06-07 20:48:39', 0),
(868, 2, 5, '2026-06-07 20:49:37', 0),
(869, 2, 8, '2026-06-07 20:51:32', 0),
(870, 2, 1, '2026-06-08 10:49:53', 0),
(871, 2, 3, '2026-06-08 14:23:08', 0),
(872, 2, 3, '2026-06-08 15:32:01', 0),
(873, 1, 3, '2026-06-08 16:45:33', 0),
(874, 1, 3, '2026-06-08 18:17:32', 0),
(875, 1, 13, '2026-06-08 18:24:16', 0),
(876, 2, 5, '2026-06-08 18:30:26', 0),
(877, 2, 8, '2026-06-08 18:31:32', 0),
(878, 2, 3, '2026-06-08 18:46:52', 0),
(879, 13, 8, '2026-06-08 18:55:58', 0),
(880, 13, 2, '2026-06-08 18:57:52', 0),
(881, 13, 1, '2026-06-08 18:58:26', 0),
(882, 2, 4, '2026-06-08 20:06:33', 0),
(883, 2, 7, '2026-06-08 20:09:00', 0),
(884, 2, 3, '2026-06-08 20:11:41', 0),
(885, 1, 4, '2026-06-08 20:12:35', 0),
(886, 1, 7, '2026-06-08 20:13:55', 0),
(887, 1, 8, '2026-06-09 04:27:03', 0),
(888, 2, 3, '2026-06-09 05:28:53', 0),
(889, 2, 1, '2026-06-09 06:05:07', 0),
(890, 2, 7, '2026-06-09 06:12:12', 0),
(891, 2, 8, '2026-06-09 06:12:34', 0),
(892, 2, 4, '2026-06-09 07:27:49', 0),
(893, 2, 1, '2026-06-09 08:02:42', 0),
(894, 13, 3, '2026-06-09 08:07:36', 0),
(895, 13, 2, '2026-06-09 08:13:14', 0),
(896, 2, 4, '2026-06-09 09:42:46', 0),
(897, 2, 5, '2026-06-09 09:43:44', 0),
(898, 13, 2, '2026-06-09 10:14:55', 0),
(899, 5, 13, '2026-06-09 17:51:03', 0),
(900, 5, 2, '2026-06-09 19:04:23', 0),
(901, 2, 3, '2026-06-09 19:16:10', 0),
(902, 2, 8, '2026-06-09 19:16:18', 0),
(903, 2, 1, '2026-06-09 19:16:25', 0),
(904, 2, 5, '2026-06-09 19:17:16', 0),
(905, 2, 7, '2026-06-10 05:27:56', 0),
(906, 2, 7, '2026-06-10 07:17:15', 0),
(907, 13, 7, '2026-06-10 07:19:36', 0),
(908, 13, 3, '2026-06-10 07:30:02', 0),
(909, 2, 8, '2026-06-10 08:15:46', 0),
(910, 13, 4, '2026-06-10 08:29:50', 0),
(911, 13, 3, '2026-06-10 08:59:39', 0),
(912, 2, 3, '2026-06-10 09:00:16', 0),
(913, 13, 3, '2026-06-10 10:37:57', 0),
(914, 4, 8, '2026-06-10 12:15:21', 0),
(915, 4, 5, '2026-06-11 04:16:30', 0),
(916, 4, 1, '2026-06-11 07:03:51', 0),
(917, 4, 3, '2026-06-11 07:05:09', 0),
(918, 4, 8, '2026-06-11 07:07:17', 0),
(919, 4, 1, '2026-06-11 08:04:51', 0),
(920, 4, 1, '2026-06-11 11:31:11', 0),
(921, 2, 3, '2026-06-11 16:23:15', 0),
(922, 2, 3, '2026-06-12 08:12:05', 0),
(923, 2, 3, '2026-06-12 09:14:55', 0),
(924, 2, 1, '2026-06-12 09:17:38', 0),
(925, 2, 5, '2026-06-12 10:39:38', 0),
(926, 2, 1, '2026-06-12 10:39:56', 0),
(927, 2, 3, '2026-06-12 10:40:10', 0),
(928, 3, 8, '2026-06-12 12:16:40', 0),
(929, 3, 2, '2026-06-12 12:45:24', 0),
(930, 7, 5, '2026-06-12 16:04:21', 0),
(931, 7, 3, '2026-06-12 16:16:27', 0),
(932, 7, 3, '2026-06-12 20:55:12', 0),
(933, 3, 7, '2026-06-12 21:27:25', 0),
(934, 3, 7, '2026-06-12 22:43:05', 0),
(935, 7, 3, '2026-06-12 23:02:50', 0),
(936, 1, 3, '2026-06-12 23:03:18', 0),
(937, 4, 3, '2026-06-12 23:03:34', 0),
(938, 8, 3, '2026-06-12 23:03:56', 0),
(939, 2, 3, '2026-06-12 23:04:18', 0),
(940, 13, 3, '2026-06-12 23:04:40', 0),
(941, 3, 2, '2026-06-12 23:08:05', 0),
(942, 1, 2, '2026-06-12 23:08:46', 0),
(943, 4, 2, '2026-06-12 23:09:11', 0),
(944, 8, 2, '2026-06-12 23:09:24', 0),
(945, 2, 13, '2026-06-12 23:37:59', 0),
(946, 1, 2, '2026-06-13 08:29:57', 0),
(947, 3, 7, '2026-06-13 08:35:05', 0),
(948, 1, 3, '2026-06-13 09:02:58', 0),
(949, 1, 3, '2026-06-13 10:23:12', 0),
(950, 1, 4, '2026-06-13 15:45:42', 0),
(951, 1, 5, '2026-06-13 16:47:22', 0),
(952, 3, 1, '2026-06-14 10:01:22', 0),
(953, 1, 2, '2026-06-14 10:17:13', 0),
(954, 1, 3, '2026-06-14 10:21:25', 0),
(955, 1, 8, '2026-06-14 10:37:04', 0),
(956, 1, 7, '2026-06-14 10:42:24', 0),
(957, 1, 7, '2026-06-14 16:14:26', 0),
(958, 1, 3, '2026-06-14 16:33:49', 0),
(959, 1, 4, '2026-06-14 16:34:59', 0),
(960, 1, 8, '2026-06-14 17:38:52', 0),
(961, 1, 4, '2026-06-14 17:39:54', 0),
(962, 1, 5, '2026-06-14 17:43:44', 0),
(963, 1, 2, '2026-06-14 18:09:04', 0),
(964, 1, 13, '2026-06-14 18:10:27', 0),
(965, 3, 1, '2026-06-14 18:26:00', 0),
(966, 1, 4, '2026-06-14 18:41:08', 0),
(967, 1, 5, '2026-06-14 18:48:35', 0),
(968, 1, 3, '2026-06-14 18:55:28', 0),
(969, 1, 4, '2026-06-14 19:44:06', 0),
(970, 1, 2, '2026-06-14 20:12:25', 0),
(971, 1, 4, '2026-06-14 20:44:39', 0),
(972, 1, 4, '2026-06-14 22:43:35', 0),
(973, 2, 4, '2026-06-14 23:25:08', 0),
(974, 2, 7, '2026-06-14 23:29:05', 0),
(975, 2, 5, '2026-06-14 23:34:26', 0),
(976, 2, 13, '2026-06-14 23:34:33', 0),
(977, 2, 3, '2026-06-15 00:21:53', 0),
(978, 13, 2, '2026-06-15 00:37:05', 0),
(979, 2, 4, '2026-06-15 00:38:14', 0),
(980, 4, 1, '2026-06-15 00:51:05', 0),
(981, 1, 7, '2026-06-15 06:50:23', 0),
(982, 7, 4, '2026-06-15 07:10:56', 0),
(983, 8, 4, '2026-06-15 07:11:28', 0),
(984, 8, 1, '2026-06-15 07:13:14', 0),
(985, 8, 3, '2026-06-15 07:18:04', 0),
(986, 8, 4, '2026-06-15 08:59:35', 0),
(987, 8, 1, '2026-06-15 09:15:59', 0),
(988, 8, 2, '2026-06-15 10:05:51', 0),
(989, 8, 4, '2026-06-15 10:08:55', 0),
(990, 8, 2, '2026-06-15 11:33:21', 0),
(991, 8, 2, '2026-06-15 12:46:09', 0),
(992, 8, 2, '2026-06-15 15:29:47', 0),
(993, 8, 4, '2026-06-15 15:29:53', 0),
(994, 8, 1, '2026-06-15 15:43:52', 0),
(995, 8, 3, '2026-06-15 15:59:05', 0),
(996, 2, 1, '2026-06-15 17:21:08', 0),
(998, 2, 13, '2026-06-16 05:58:44', 0),
(999, 16, 3, '2026-06-16 06:37:11', 0),
(1000, 16, 5, '2026-06-16 06:38:01', 0),
(1001, 3, 13, '2026-06-16 06:43:45', 0),
(1002, 3, 16, '2026-06-16 06:45:30', 0),
(1003, 2, 16, '2026-06-16 06:47:43', 0),
(1004, 2, 3, '2026-06-16 06:50:49', 0),
(1005, 16, 7, '2026-06-16 07:03:53', 0),
(1007, 4, 16, '2026-06-16 07:04:32', 0),
(1008, 16, 4, '2026-06-16 07:08:21', 0),
(1009, 16, 2, '2026-06-16 07:35:41', 0),
(1010, 16, 1, '2026-06-16 07:39:02', 0),
(1011, 16, 7, '2026-06-16 08:05:39', 0),
(1012, 16, 3, '2026-06-16 08:09:23', 0),
(1013, 16, 2, '2026-06-16 08:43:11', 0),
(1014, 16, 4, '2026-06-16 08:55:17', 0),
(1015, 16, 5, '2026-06-16 09:11:53', 0),
(1016, 16, 1, '2026-06-16 09:19:02', 0),
(1017, 16, 3, '2026-06-16 09:27:08', 0),
(1018, 16, 7, '2026-06-16 09:32:15', 0),
(1019, 16, 13, '2026-06-16 09:32:28', 0),
(1020, 3, 16, '2026-06-16 09:51:51', 0),
(1021, 16, 1, '2026-06-16 10:24:38', 0),
(1022, 16, 3, '2026-06-16 11:13:10', 0),
(1023, 16, 13, '2026-06-16 11:20:23', 0),
(1024, 3, 16, '2026-06-16 11:23:06', 0),
(1025, 16, 7, '2026-06-16 11:54:54', 0),
(1026, 16, 4, '2026-06-16 11:55:32', 0),
(1027, 16, 2, '2026-06-16 11:57:29', 0),
(1028, 16, 3, '2026-06-16 16:47:27', 0),
(1029, 16, 4, '2026-06-16 16:52:38', 0),
(1030, 16, 8, '2026-06-16 16:56:12', 0),
(1031, 1, 16, '2026-06-16 17:00:22', 0),
(1032, 1, 5, '2026-06-16 17:07:03', 0),
(1033, 1, 4, '2026-06-16 18:01:33', 0),
(1034, 1, 13, '2026-06-16 18:02:00', 0),
(1035, 1, 3, '2026-06-16 18:07:34', 0),
(1040, 1, 4, '2026-06-17 04:10:59', 0),
(1041, 1, 3, '2026-06-17 04:46:13', 0),
(1042, 1, 5, '2026-06-17 05:05:04', 0),
(1043, 1, 4, '2026-06-17 05:12:10', 0),
(1044, 1, 3, '2026-06-17 05:53:13', 0),
(1046, 1, 5, '2026-06-17 06:47:34', 0),
(1047, 1, 7, '2026-06-17 06:47:57', 0),
(1048, 1, 3, '2026-06-17 06:53:14', 0),
(1049, 1, 3, '2026-06-17 07:57:00', 0),
(1050, 1, 5, '2026-06-17 11:35:04', 0),
(1051, 1, 3, '2026-06-17 15:11:14', 0),
(1052, 3, 8, '2026-06-17 20:07:11', 0),
(1053, 2, 5, '2026-06-18 06:33:25', 0),
(1054, 2, 3, '2026-06-19 03:34:11', 0),
(1055, 2, 4, '2026-06-19 05:20:04', 0),
(1056, 1, 7, '2026-06-19 05:28:28', 0),
(1057, 1, 3, '2026-06-19 05:30:20', 0),
(1058, 1, 4, '2026-06-19 05:36:27', 0),
(1059, 1, 5, '2026-06-19 05:38:49', 0),
(1060, 13, 1, '2026-06-19 07:12:45', 0),
(1061, 13, 16, '2026-06-19 07:13:03', 0),
(1062, 2, 3, '2026-06-19 07:29:41', 0),
(1063, 2, 3, '2026-06-19 08:34:43', 0),
(1064, 2, 4, '2026-06-19 08:40:55', 0),
(1065, 2, 5, '2026-06-19 08:54:26', 0),
(1066, 2, 4, '2026-06-19 12:06:37', 0),
(1067, 2, 1, '2026-06-19 12:25:01', 0),
(1068, 2, 1, '2026-06-19 13:28:30', 0),
(1069, 2, 3, '2026-06-19 13:52:55', 0),
(1070, 2, 16, '2026-06-19 13:58:56', 0),
(1071, 2, 4, '2026-06-19 13:59:10', 0),
(1072, 2, 8, '2026-06-19 14:14:40', 0),
(1073, 2, 7, '2026-06-19 14:14:47', 0),
(1074, 2, 1, '2026-06-19 14:29:37', 0),
(1075, 2, 8, '2026-06-19 15:23:37', 0),
(1076, 2, 8, '2026-06-19 19:14:18', 0),
(1077, 2, 8, '2026-06-20 04:54:18', 0),
(1078, 2, 5, '2026-06-20 04:56:52', 0),
(1079, 2, 7, '2026-06-20 04:57:05', 0),
(1080, 3, 4, '2026-06-20 10:00:31', 0),
(1081, 3, 1, '2026-06-20 10:02:17', 0),
(1082, 3, 4, '2026-06-20 12:46:36', 0),
(1083, 4, 3, '2026-06-20 12:47:44', 0),
(1084, 3, 4, '2026-06-20 21:34:42', 0),
(1085, 3, 4, '2026-06-21 04:15:00', 0),
(1086, 3, 7, '2026-06-21 04:48:48', 0),
(1087, 3, 16, '2026-06-21 05:34:03', 0),
(1088, 3, 2, '2026-06-21 05:34:09', 0),
(1089, 3, 4, '2026-06-21 06:33:47', 0),
(1090, 3, 5, '2026-06-21 06:34:07', 0),
(1091, 4, 3, '2026-06-21 06:34:27', 0),
(1092, 3, 16, '2026-06-21 07:23:35', 0),
(1093, 3, 4, '2026-06-21 07:37:01', 0),
(1094, 3, 2, '2026-06-21 07:37:43', 0),
(1095, 3, 8, '2026-06-21 07:44:54', 0),
(1096, 3, 13, '2026-06-21 07:51:42', 0),
(1097, 3, 1, '2026-06-21 08:14:28', 0),
(1098, 3, 8, '2026-06-21 09:19:10', 0),
(1099, 3, 8, '2026-06-21 13:28:37', 0),
(1100, 4, 3, '2026-06-21 13:32:16', 0),
(1101, 13, 5, '2026-06-21 15:02:40', 0),
(1102, 13, 3, '2026-06-21 15:05:27', 0),
(1103, 2, 4, '2026-06-21 15:05:59', 0),
(1104, 13, 8, '2026-06-21 15:10:06', 0),
(1105, 13, 2, '2026-06-21 15:10:28', 0),
(1106, 13, 16, '2026-06-21 15:10:44', 0),
(1107, 2, 3, '2026-06-21 15:24:12', 0),
(1108, 2, 1, '2026-06-21 15:32:27', 0),
(1109, 2, 5, '2026-06-21 15:38:07', 0),
(1110, 13, 4, '2026-06-21 15:50:05', 0),
(1111, 13, 16, '2026-06-22 04:05:12', 0),
(1112, 3, 4, '2026-06-22 06:00:02', 0),
(1113, 1, 13, '2026-06-22 06:16:20', 0),
(1114, 1, 4, '2026-06-22 06:21:49', 0),
(1115, 2, 4, '2026-06-22 06:44:59', 0),
(1116, 4, 8, '2026-06-22 07:32:17', 0),
(1117, 4, 16, '2026-06-22 07:33:05', 0),
(1118, 1, 16, '2026-06-22 07:52:58', 0),
(1119, 1, 4, '2026-06-22 08:00:12', 0),
(1120, 1, 2, '2026-06-22 08:19:13', 0),
(1121, 1, 3, '2026-06-22 08:32:39', 0),
(1122, 1, 16, '2026-06-22 08:53:34', 0),
(1123, 1, 4, '2026-06-22 09:04:31', 0),
(1124, 1, 4, '2026-06-22 10:05:54', 0),
(1125, 1, 5, '2026-06-22 10:06:22', 0),
(1126, 1, 3, '2026-06-22 10:45:08', 0),
(1127, 1, 16, '2026-06-22 10:45:56', 0),
(1128, 13, 3, '2026-06-22 10:52:54', 0),
(1129, 13, 2, '2026-06-22 11:01:13', 0),
(1130, 13, 5, '2026-06-22 11:21:55', 0),
(1131, 13, 16, '2026-06-22 11:27:00', 0),
(1132, 2, 4, '2026-06-22 11:46:04', 0),
(1133, 2, 16, '2026-06-22 11:46:31', 0),
(1134, 4, 2, '2026-06-22 11:47:35', 0),
(1135, 3, 5, '2026-06-22 12:39:03', 0),
(1136, 3, 7, '2026-06-22 12:39:35', 0),
(1137, 3, 2, '2026-06-22 12:41:33', 0),
(1138, 3, 4, '2026-06-22 12:42:05', 0),
(1139, 3, 4, '2026-06-22 14:06:12', 0),
(1140, 3, 4, '2026-06-22 15:33:00', 0),
(1141, 3, 4, '2026-06-22 16:37:32', 0),
(1142, 3, 4, '2026-06-23 01:13:42', 0),
(1143, 3, 4, '2026-06-23 02:17:58', 0),
(1144, 3, 8, '2026-06-23 02:33:22', 0),
(1147, 7, 8, '2026-06-23 02:35:45', 0),
(1148, 3, 7, '2026-06-23 02:36:08', 0),
(1149, 3, 5, '2026-06-23 03:45:22', 0),
(1150, 3, 1, '2026-06-23 04:11:12', 0),
(1151, 3, 8, '2026-06-23 04:11:43', 0),
(1152, 3, 16, '2026-06-23 04:19:49', 0),
(1153, 3, 16, '2026-06-23 06:26:10', 0),
(1154, 3, 1, '2026-06-23 06:27:38', 0),
(1155, 3, 4, '2026-06-23 07:27:18', 0),
(1156, 3, 4, '2026-06-23 08:40:12', 0),
(1157, 3, 1, '2026-06-23 09:23:59', 0),
(1161, 3, 1, '2026-06-23 11:36:33', 0),
(1162, 3, 5, '2026-06-23 16:20:26', 0),
(1163, 3, 8, '2026-06-23 16:58:17', 0),
(1164, 3, 7, '2026-06-24 04:38:43', 0),
(1165, 3, 5, '2026-06-24 06:04:00', 0),
(1166, 3, 2, '2026-06-24 06:04:07', 0),
(1167, 3, 8, '2026-06-24 06:04:15', 0),
(1168, 3, 4, '2026-06-24 08:10:30', 0),
(1169, 3, 8, '2026-06-25 07:07:17', 0),
(1170, 3, 4, '2026-06-25 07:24:25', 0),
(1171, 3, 7, '2026-06-25 14:46:51', 0),
(1172, 3, 7, '2026-06-27 04:50:15', 0),
(1173, 3, 1, '2026-06-27 05:28:44', 0),
(1174, 3, 16, '2026-06-27 06:09:47', 0),
(1175, 13, 2, '2026-06-27 06:11:22', 0),
(1176, 13, 7, '2026-06-27 06:11:56', 0),
(1177, 3, 13, '2026-06-27 06:24:26', 0),
(1178, 3, 8, '2026-06-27 07:32:54', 0),
(1179, 3, 1, '2026-06-27 08:56:45', 0),
(1180, 3, 7, '2026-06-27 09:50:05', 0),
(1181, 3, 4, '2026-06-27 10:00:55', 0),
(1182, 3, 2, '2026-06-27 10:24:11', 0),
(1183, 13, 2, '2026-06-27 10:30:09', 0),
(1184, 3, 16, '2026-06-27 10:32:00', 0),
(1185, 3, 4, '2026-06-27 14:34:59', 0),
(1186, 8, 16, '2026-06-27 17:04:04', 0),
(1187, 3, 4, '2026-06-28 05:23:58', 0),
(1188, 3, 8, '2026-06-28 09:33:06', 0),
(1189, 2, 5, '2026-07-01 16:38:41', 0),
(1190, 2, 3, '2026-07-01 16:39:13', 0),
(1191, 2, 16, '2026-07-01 16:41:18', 0),
(1192, 8, 3, '2026-07-01 17:16:47', 0),
(1193, 8, 7, '2026-07-01 18:24:08', 0),
(1194, 8, 13, '2026-07-01 18:27:55', 0),
(1195, 8, 4, '2026-07-01 18:28:24', 0),
(1196, 8, 1, '2026-07-01 19:18:25', 0),
(1197, 8, 7, '2026-07-02 05:35:20', 0),
(1198, 8, 5, '2026-07-02 06:27:00', 0),
(1199, 8, 4, '2026-07-02 06:29:32', 0),
(1200, 1, 8, '2026-07-02 06:30:26', 0),
(1201, 1, 2, '2026-07-02 06:39:10', 0),
(1202, 1, 8, '2026-07-02 07:37:43', 0),
(1203, 2, 8, '2026-07-02 08:10:35', 0),
(1204, 13, 8, '2026-07-02 08:11:20', 0),
(1205, 4, 8, '2026-07-02 08:39:36', 0),
(1206, 7, 8, '2026-07-02 08:45:10', 0),
(1207, 8, 16, '2026-07-02 08:59:12', 0),
(1209, 7, 8, '2026-07-02 10:17:42', 0),
(1211, 1, 3, '2026-07-02 11:37:30', 0),
(1212, 1, 8, '2026-07-02 11:55:08', 0),
(1213, 1, 3, '2026-07-02 13:50:54', 0),
(1214, 1, 7, '2026-07-02 13:53:18', 0),
(1215, 1, 5, '2026-07-02 14:48:06', 0),
(1216, 1, 8, '2026-07-03 15:03:41', 0),
(1217, 1, 3, '2026-07-03 15:40:23', 0),
(1218, 3, 7, '2026-07-03 16:31:39', 0),
(1219, 3, 2, '2026-07-03 16:36:35', 0),
(1220, 7, 3, '2026-07-03 18:52:02', 0),
(1221, 7, 5, '2026-07-03 18:55:22', 0),
(1222, 7, 8, '2026-07-03 19:00:51', 0),
(1223, 7, 8, '2026-07-03 21:39:57', 0),
(1224, 7, 3, '2026-07-03 21:43:52', 0),
(1225, 7, 1, '2026-07-03 21:59:43', 0),
(1226, 7, 5, '2026-07-03 22:16:00', 0),
(1227, 7, 13, '2026-07-03 22:17:36', 0),
(1228, 7, 16, '2026-07-03 22:19:07', 0),
(1229, 7, 2, '2026-07-03 22:22:40', 0),
(1230, 7, 4, '2026-07-03 22:26:36', 0),
(1231, 7, 4, '2026-07-04 12:10:29', 0),
(1232, 7, 3, '2026-07-04 12:10:35', 0),
(1233, 7, 3, '2026-07-05 06:10:40', 0),
(1234, 7, 2, '2026-07-05 07:08:53', 0),
(1235, 7, 3, '2026-07-05 07:12:49', 0),
(1236, 7, 8, '2026-07-05 07:14:34', 0),
(1237, 1, 2, '2026-07-05 07:17:51', 0),
(1238, 1, 3, '2026-07-05 07:18:36', 0),
(1239, 1, 8, '2026-07-05 07:19:33', 0),
(1240, 1, 13, '2026-07-05 07:22:19', 0),
(1241, 1, 4, '2026-07-05 07:27:42', 0),
(1242, 1, 16, '2026-07-05 07:27:53', 0),
(1243, 1, 5, '2026-07-05 07:42:56', 0),
(1244, 1, 7, '2026-07-05 10:52:40', 0),
(1245, 1, 2, '2026-07-05 11:01:08', 0),
(1246, 1, 8, '2026-07-05 11:05:07', 0),
(1247, 1, 3, '2026-07-05 11:12:41', 0),
(1248, 1, 13, '2026-07-05 11:31:50', 0),
(1249, 1, 5, '2026-07-05 11:34:20', 0),
(1250, 1, 4, '2026-07-05 11:43:44', 0),
(1251, 1, 7, '2026-07-05 11:58:55', 0),
(1252, 1, 8, '2026-07-05 12:10:10', 0),
(1253, 8, 1, '2026-07-05 13:05:08', 0),
(1254, 8, 7, '2026-07-05 13:12:21', 0),
(1255, 7, 8, '2026-07-05 13:16:32', 0),
(1256, 7, 3, '2026-07-05 13:18:39', 0),
(1257, 7, 4, '2026-07-05 13:21:25', 0),
(1258, 7, 1, '2026-07-05 13:25:08', 0),
(1259, 7, 13, '2026-07-05 13:32:18', 0),
(1260, 7, 16, '2026-07-05 13:34:21', 0),
(1261, 7, 2, '2026-07-05 13:39:28', 0),
(1262, 2, 8, '2026-07-05 13:56:46', 0),
(1263, 3, 16, '2026-07-05 14:02:05', 0),
(1264, 3, 2, '2026-07-05 14:04:36', 0),
(1265, 16, 2, '2026-07-05 14:05:10', 0),
(1266, 16, 5, '2026-07-05 14:07:03', 0),
(1267, 16, 7, '2026-07-05 14:07:53', 0),
(1268, 16, 1, '2026-07-05 16:19:39', 0),
(1269, 16, 5, '2026-07-05 16:38:32', 0),
(1270, 16, 4, '2026-07-05 16:44:53', 0),
(1271, 16, 3, '2026-07-05 17:13:59', 0),
(1272, 16, 1, '2026-07-05 17:20:06', 0),
(1273, 16, 2, '2026-07-05 17:50:53', 0),
(1274, 16, 7, '2026-07-05 17:52:19', 0),
(1276, 3, 5, '2026-07-05 17:56:23', 0),
(1277, 3, 1, '2026-07-05 17:56:30', 0),
(1278, 3, 7, '2026-07-05 17:58:34', 0),
(1279, 3, 2, '2026-07-05 18:28:47', 0),
(1280, 3, 4, '2026-07-05 18:42:24', 0),
(1281, 3, 1, '2026-07-07 05:42:38', 0),
(1282, 3, 4, '2026-07-07 06:22:56', 0),
(1283, 3, 7, '2026-07-07 07:13:16', 0),
(1284, 3, 4, '2026-07-07 07:49:53', 0),
(1285, 3, 8, '2026-07-07 08:03:45', 0),
(1286, 3, 1, '2026-07-07 08:20:07', 0),
(1287, 3, 4, '2026-07-07 09:59:15', 0),
(1288, 3, 7, '2026-07-07 10:29:02', 0),
(1289, 3, 1, '2026-07-07 10:29:27', 0),
(1290, 3, 7, '2026-07-07 18:30:34', 0),
(1291, 3, 2, '2026-07-08 03:57:58', 0),
(1292, 3, 1, '2026-07-08 05:27:23', 0),
(1293, 3, 2, '2026-07-08 07:44:56', 0),
(1296, 3, 1, '2026-07-09 12:02:54', 0),
(1297, 3, 8, '2026-07-09 13:43:03', 0),
(1298, 3, 4, '2026-07-09 13:43:56', 0),
(1299, 3, 5, '2026-07-09 13:45:09', 0),
(1300, 3, 2, '2026-07-09 13:46:37', 0),
(1301, 3, 1, '2026-07-09 14:35:07', 0),
(1302, 3, 8, '2026-07-09 14:48:50', 0),
(1303, 3, 13, '2026-07-09 14:51:02', 0),
(1304, 3, 7, '2026-07-09 14:51:15', 0),
(1305, 7, 3, '2026-07-09 15:25:53', 0),
(1306, 7, 1, '2026-07-09 15:39:40', 0),
(1307, 3, 4, '2026-07-09 15:43:22', 0),
(1308, 7, 8, '2026-07-09 15:56:22', 0),
(1309, 3, 2, '2026-07-09 16:13:48', 0),
(1310, 7, 4, '2026-07-09 16:15:07', 0),
(1311, 3, 7, '2026-07-10 06:27:16', 0),
(1312, 3, 4, '2026-07-10 06:30:46', 0),
(1313, 3, 2, '2026-07-10 06:53:36', 0),
(1314, 3, 5, '2026-07-10 06:53:49', 0),
(1315, 3, 8, '2026-07-10 06:57:37', 0),
(1316, 3, 1, '2026-07-10 07:48:22', 0),
(1317, 3, 5, '2026-07-10 07:54:36', 0),
(1318, 3, 2, '2026-07-11 10:19:55', 0),
(1322, 2, 4, '2026-07-14 05:27:25', 0),
(1323, 2, 7, '2026-07-14 08:05:53', 0),
(1324, 2, 4, '2026-07-14 08:25:45', 0),
(1325, 2, 1, '2026-07-15 07:09:59', 0),
(1326, 2, 8, '2026-07-15 07:16:39', 0),
(1327, 2, 4, '2026-07-15 07:19:16', 0),
(1328, 16, 2, '2026-07-15 07:55:26', 0),
(1329, 16, 1, '2026-07-15 08:41:12', 0),
(1330, 16, 8, '2026-07-15 09:32:16', 0),
(1331, 16, 4, '2026-07-15 09:34:59', 0),
(1332, 16, 7, '2026-07-15 10:05:17', 0),
(1333, 16, 2, '2026-07-15 10:59:36', 0),
(1334, 3, 1, '2026-07-15 12:51:35', 0),
(1335, 3, 4, '2026-07-15 13:28:54', 0),
(1336, 3, 2, '2026-07-15 13:31:11', 0),
(1337, 16, 4, '2026-07-15 14:07:53', 0),
(1338, 16, 1, '2026-07-15 20:16:27', 0),
(1339, 16, 4, '2026-07-16 06:18:32', 0),
(1340, 16, 3, '2026-07-16 06:58:17', 0),
(1341, 16, 8, '2026-07-16 07:10:04', 0),
(1342, 16, 7, '2026-07-16 07:11:45', 0),
(1343, 16, 2, '2026-07-16 07:15:14', 0),
(1344, 16, 13, '2026-07-16 07:18:14', 0),
(1345, 16, 1, '2026-07-16 07:18:40', 0),
(1348, 16, 5, '2026-07-16 07:31:28', 0),
(1349, 4, 16, '2026-07-16 07:33:02', 0),
(1350, 4, 5, '2026-07-16 07:35:01', 0),
(1351, 4, 3, '2026-07-16 07:41:37', 0),
(1352, 4, 13, '2026-07-16 07:42:21', 0),
(1353, 4, 8, '2026-07-16 07:48:43', 0),
(1354, 4, 7, '2026-07-16 07:50:53', 0),
(1355, 4, 1, '2026-07-16 07:51:07', 0),
(1356, 7, 4, '2026-07-16 15:00:13', 0),
(1357, 7, 4, '2026-07-17 05:14:16', 0),
(1358, 7, 13, '2026-07-17 05:14:28', 0),
(1359, 7, 1, '2026-07-17 05:14:42', 0),
(1360, 7, 2, '2026-07-17 05:17:50', 0),
(1361, 7, 4, '2026-07-17 12:23:26', 0),
(1362, 7, 4, '2026-07-18 04:34:28', 0),
(1363, 7, 2, '2026-07-18 11:40:19', 0),
(1364, 7, 2, '2026-07-18 12:46:31', 0),
(1365, 7, 4, '2026-07-18 13:52:15', 0),
(1366, 3, 7, '2026-07-18 17:09:01', 0),
(1367, 7, 4, '2026-07-18 19:28:54', 0),
(1368, 7, 1, '2026-07-18 19:30:40', 0),
(1369, 7, 2, '2026-07-18 20:03:38', 0),
(1370, 7, 3, '2026-07-19 05:42:18', 0),
(1371, 7, 4, '2026-07-19 05:46:26', 0),
(1372, 7, 4, '2026-07-19 06:58:13', 0),
(1373, 7, 1, '2026-07-19 08:12:24', 0);
INSERT INTO `profile_views` (`id`, `viewer_id`, `profile_id`, `viewed_at`, `seen`) VALUES
(1374, 7, 3, '2026-07-19 08:17:09', 0),
(1375, 13, 7, '2026-07-19 08:38:58', 0),
(1376, 2, 7, '2026-07-19 08:44:28', 0),
(1377, 5, 7, '2026-07-19 08:48:36', 0),
(1378, 5, 16, '2026-07-19 08:49:46', 0),
(1379, 16, 7, '2026-07-19 08:52:13', 0),
(1380, 7, 3, '2026-07-19 11:07:42', 0),
(1381, 7, 2, '2026-07-19 11:33:47', 0),
(1382, 7, 3, '2026-07-20 07:01:12', 0),
(1383, 7, 4, '2026-07-20 07:02:23', 0),
(1384, 3, 7, '2026-07-20 11:57:38', 0),
(1385, 3, 7, '2026-07-20 13:12:18', 0),
(1386, 3, 7, '2026-07-20 15:13:56', 0),
(1387, 3, 4, '2026-07-20 17:10:25', 0),
(1388, 3, 7, '2026-07-21 14:14:00', 0),
(1389, 7, 1, '2026-07-21 14:28:44', 0),
(1390, 7, 4, '2026-07-21 14:29:09', 0),
(1391, 7, 4, '2026-07-22 07:37:00', 0),
(1392, 7, 8, '2026-07-22 08:03:20', 0),
(1396, 3, 7, '2026-07-22 09:18:01', 0),
(1397, 7, 1, '2026-07-22 09:24:05', 0),
(1398, 7, 3, '2026-07-22 09:33:19', 0),
(1399, 7, 8, '2026-07-22 09:35:53', 0),
(1400, 7, 16, '2026-07-22 09:48:41', 0),
(1401, 7, 2, '2026-07-22 10:01:08', 0),
(1402, 7, 1, '2026-07-22 10:28:29', 0),
(1403, 7, 3, '2026-07-22 10:41:32', 0),
(1404, 7, 8, '2026-07-22 10:47:41', 0),
(1405, 7, 3, '2026-07-22 12:07:08', 0),
(1406, 7, 4, '2026-07-22 16:33:58', 0),
(1407, 7, 8, '2026-07-22 16:55:55', 0),
(1408, 7, 3, '2026-07-22 16:56:46', 0),
(1409, 3, 7, '2026-07-22 17:25:36', 0),
(1410, 7, 4, '2026-07-22 18:01:27', 0),
(1411, 7, 4, '2026-07-23 05:42:40', 0),
(1412, 7, 1, '2026-07-23 09:18:16', 0),
(1413, 7, 3, '2026-07-23 10:36:07', 0),
(1414, 3, 7, '2026-07-23 10:40:16', 0),
(1415, 7, 1, '2026-07-23 11:29:13', 0),
(1416, 7, 4, '2026-07-23 19:09:35', 0),
(1417, 7, 4, '2026-07-24 09:42:57', 0),
(1418, 7, 2, '2026-07-24 10:15:57', 0),
(1419, 7, 1, '2026-07-24 10:34:50', 0),
(1420, 7, 13, '2026-07-24 16:11:09', 0),
(1421, 7, 8, '2026-07-24 16:53:02', 0),
(1422, 7, 3, '2026-07-24 17:46:47', 0),
(1423, 7, 13, '2026-07-25 06:27:54', 0),
(1424, 7, 2, '2026-07-25 06:31:54', 0),
(1425, 7, 19, '2026-07-25 06:43:57', 0),
(1426, 7, 3, '2026-07-25 07:50:49', 0),
(1427, 19, 4, '2026-07-25 08:01:18', 0),
(1428, 7, 19, '2026-07-25 08:03:51', 0),
(1429, 7, 16, '2026-07-25 08:09:21', 0),
(1430, 7, 13, '2026-07-25 08:14:42', 0),
(1431, 7, 5, '2026-07-25 08:34:57', 0),
(1432, 7, 8, '2026-07-25 08:52:28', 0),
(1463, 19, 7, '2026-07-26 14:52:11', 0),
(1465, 7, 3, '2026-07-27 05:00:46', 0),
(1466, 7, 3, '2026-07-29 11:31:01', 0),
(1467, 7, 2, '2026-07-29 11:57:15', 0),
(1468, 7, 4, '2026-07-29 12:05:03', 0),
(1469, 7, 8, '2026-07-29 12:11:09', 0),
(1470, 7, 3, '2026-07-29 15:15:58', 0),
(1471, 7, 19, '2026-07-29 15:32:52', 0),
(1472, 3, 7, '2026-07-29 19:33:41', 0),
(1473, 3, 7, '2026-07-30 08:15:26', 0),
(1474, 7, 3, '2026-07-31 10:07:42', 0),
(1475, 7, 1, '2026-07-31 10:28:22', 0),
(1476, 7, 4, '2026-07-31 10:33:07', 0),
(1477, 7, 3, '2026-07-31 11:13:57', 0),
(1478, 7, 1, '2026-07-31 11:31:25', 0),
(1479, 7, 16, '2026-07-31 11:45:10', 0),
(1480, 7, 5, '2026-07-31 11:51:19', 0),
(1481, 7, 4, '2026-07-31 11:53:47', 0),
(1482, 7, 8, '2026-07-31 12:00:20', 0),
(1483, 7, 19, '2026-07-31 12:05:11', 0),
(1484, 7, 2, '2026-07-31 12:06:29', 0),
(1485, 7, 3, '2026-07-31 12:15:24', 0),
(1486, 7, 3, '2026-07-31 18:44:37', 0),
(1487, 19, 7, '2026-07-31 19:13:18', 0),
(1488, 7, 3, '2026-07-31 20:16:07', 0),
(1489, 7, 1, '2026-07-31 20:41:56', 0),
(1490, 7, 3, '2026-08-01 06:35:51', 0),
(1491, 7, 3, '2026-08-01 08:08:58', 0),
(1492, 7, 1, '2026-08-01 08:15:39', 0),
(1493, 7, 4, '2026-08-01 08:36:05', 0),
(1494, 7, 8, '2026-08-01 09:01:30', 0),
(1495, 19, 7, '2026-08-01 09:43:47', 0),
(1496, 19, 3, '2026-08-01 09:44:40', 0),
(1497, 7, 3, '2026-08-01 10:22:13', 0),
(1498, 7, 3, '2026-08-01 11:26:04', 0),
(1499, 7, 3, '2026-08-01 12:29:09', 0),
(1500, 7, 2, '2026-08-01 12:33:00', 0);

-- --------------------------------------------------------

--
-- Table structure for table `profile_view_hidden`
--

CREATE TABLE `profile_view_hidden` (
  `id` int(10) UNSIGNED NOT NULL,
  `hider_id` int(10) UNSIGNED NOT NULL,
  `target_id` int(10) UNSIGNED NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `remix`
--

CREATE TABLE `remix` (
  `id` int(10) UNSIGNED NOT NULL,
  `post_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `root_id` int(10) UNSIGNED NOT NULL,
  `content` varchar(255) NOT NULL,
  `caption` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `parent_id` int(10) UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `remix`
--

INSERT INTO `remix` (`id`, `post_id`, `user_id`, `root_id`, `content`, `caption`, `created_at`, `parent_id`) VALUES
(1, 20, 3, 20, '1774172328_7826.mp4', 'lol', '2026-03-23 15:35:39', 20),
(2, 28, 3, 28, '1774268165_8615.jpg', 'lol', '2026-03-23 15:40:09', 28),
(3, 28, 3, 28, '1774268165_8615.jpg', 'lol', '2026-03-23 15:45:33', 28),
(4, 20, 3, 20, '1774172328_7826.mp4', 'lol', '2026-03-23 15:45:45', 20),
(5, 20, 3, 20, '1774172328_7826.mp4', 'lol', '2026-03-23 15:53:06', 20),
(6, 28, 3, 28, '1774268165_8615.jpg', 'lol', '2026-03-23 15:58:19', 28),
(7, 28, 3, 28, '1774268165_8615.jpg', 'lol', '2026-03-23 16:00:17', 28),
(8, 28, 3, 28, '1774268165_8615.jpg', 'yh', '2026-03-23 16:00:59', 28),
(9, 20, 3, 20, '1774172328_7826.mp4', 'yh', '2026-03-23 16:01:44', 20),
(10, 20, 3, 20, '1774172328_7826.mp4', 'lol', '2026-03-23 16:08:51', 20),
(11, 20, 3, 20, '1774172328_7826.mp4', 'Type shit', '2026-03-23 16:51:24', 20),
(12, 18, 1, 18, '0', 'lol', '2026-03-24 05:03:30', 18),
(13, 28, 1, 28, '1774268165_8615.jpg', 'lol i hat this school [Caelix] (Remixed)', '2026-03-24 09:35:52', 28),
(14, 33, 1, 33, '1774268165_8615.jpg', '↩️ lol @Tyven', '2026-03-24 10:19:06', 33),
(15, 34, 1, 34, '1774268165_8615.jpg', '↩️ lol @Tyven', '2026-03-24 10:21:28', 34),
(16, 4, 1, 4, '1774070977_7084.jpg', '↩️ This is too real. @Leah', '2026-03-24 10:23:18', 4),
(17, 36, 5, 36, '1774070977_7084.jpg', '↩️ lol. @Tyven', '2026-03-24 10:28:11', 36),
(18, 38, 1, 38, '1774418756_6623.jpg', '↩️ lol This is too real @Leah', '2026-03-25 06:07:14', 38),
(19, 11, 3, 11, '1774101129_video.mp4', '↩️ lol @Leah #funny', '2026-04-07 06:43:23', 11),
(20, 1, 3, 1, '1774069791_3918.jpg', '↩️ This is so true #funny memes @Tyven', '2026-04-07 06:51:18', 1),
(21, 19, 3, 19, '0', '↩️ now #love @Tyven', '2026-04-07 06:58:01', 19),
(22, 31, 3, 31, '1774289901_2116.mp4', '↩️ lim #Lol @Tyven', '2026-04-07 07:03:00', 31),
(23, 11, 2, 11, '1774101129_video.mp4', '↩️ lol they can never #horror @Leah', '2026-04-07 09:57:58', 11),
(24, 16, 1, 16, '0', '↩️ lol too real dani #me @Dani', '2026-05-13 16:04:53', 16);

-- --------------------------------------------------------

--
-- Table structure for table `report_action_log`
--

CREATE TABLE `report_action_log` (
  `id` int(10) UNSIGNED NOT NULL,
  `report_type` enum('user','content','pod') NOT NULL,
  `report_id` int(10) UNSIGNED NOT NULL,
  `admin_id` int(10) UNSIGNED NOT NULL,
  `action` varchar(80) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `reposts`
--

CREATE TABLE `reposts` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `meme_id` int(10) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `reposts`
--

INSERT INTO `reposts` (`id`, `user_id`, `meme_id`, `created_at`) VALUES
(49, 4, 3, '2026-03-25 06:11:05'),
(50, 4, 1, '2026-03-25 06:11:08'),
(53, 5, 48, '2026-03-26 08:26:35'),
(54, 3, 39, '2026-03-26 10:08:02'),
(63, 1, 54, '2026-03-28 07:53:31'),
(64, 1, 11, '2026-03-28 07:58:58'),
(75, 4, 11, '2026-03-29 05:06:11'),
(77, 4, 7, '2026-03-29 06:02:31'),
(81, 4, 6, '2026-03-30 09:26:10'),
(83, 7, 7, '2026-04-09 05:20:45'),
(92, 3, 31, '2026-04-19 03:11:06'),
(107, 3, 11, '2026-04-19 07:53:17'),
(119, 2, 48, '2026-04-20 06:21:59'),
(120, 7, 54, '2026-04-20 07:30:39'),
(123, 8, 66, '2026-04-20 18:56:32'),
(124, 8, 40, '2026-04-20 18:58:59'),
(126, 8, 67, '2026-04-20 19:01:18'),
(127, 3, 68, '2026-04-20 19:03:20'),
(130, 7, 38, '2026-04-21 17:26:02'),
(133, 1, 16, '2026-04-23 08:30:48'),
(134, 1, 19, '2026-04-23 08:30:51'),
(137, 1, 38, '2026-04-23 10:00:12'),
(147, 2, 7, '2026-05-09 14:21:53'),
(149, 5, 68, '2026-05-12 12:31:40'),
(158, 3, 19, '2026-05-17 22:06:41'),
(163, 4, 85, '2026-05-22 08:23:18'),
(170, 4, 31, '2026-05-23 06:06:32'),
(188, 16, 1, '2026-07-15 08:39:44'),
(189, 16, 39, '2026-07-15 08:40:21'),
(191, 7, 1, '2026-07-19 07:17:56'),
(192, 3, 18, '2026-07-19 08:55:50'),
(193, 7, 18, '2026-07-20 07:13:14'),
(196, 7, 68, '2026-07-20 07:22:22'),
(198, 7, 6, '2026-07-20 07:30:24'),
(206, 7, 67, '2026-07-20 07:55:27'),
(221, 7, 124, '2026-07-29 12:21:08'),
(225, 7, 139, '2026-07-30 06:10:32');

-- --------------------------------------------------------

--
-- Table structure for table `reset_attempts`
--

CREATE TABLE `reset_attempts` (
  `id` int(10) UNSIGNED NOT NULL,
  `identifier` varchar(255) NOT NULL,
  `attempted_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `reset_attempts`
--

INSERT INTO `reset_attempts` (`id`, `identifier`, `attempted_at`) VALUES
(1, 'eff8e7ca506627fe15dda5e0e512fcaad70b6d520f37cc76597fdb4f2d83a1a3', '2026-06-03 15:54:03');

-- --------------------------------------------------------

--
-- Table structure for table `restricted_users`
--

CREATE TABLE `restricted_users` (
  `id` int(10) UNSIGNED NOT NULL,
  `restrictor_id` int(10) UNSIGNED NOT NULL,
  `restricted_id` int(10) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `saved_sounds`
--

CREATE TABLE `saved_sounds` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `sound_id` int(10) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `saved_sounds`
--

INSERT INTO `saved_sounds` (`id`, `user_id`, `sound_id`, `created_at`) VALUES
(3, 1, 4, '2026-04-05 03:21:05'),
(5, 3, 4, '2026-04-05 03:33:54'),
(6, 3, 12, '2026-04-05 03:47:27'),
(7, 1, 12, '2026-04-05 03:48:09'),
(8, 4, 4, '2026-04-05 04:08:20'),
(9, 4, 12, '2026-04-05 04:08:22'),
(10, 16, 12, '2026-06-16 16:48:24');

-- --------------------------------------------------------

--
-- Table structure for table `search_queries`
--

CREATE TABLE `search_queries` (
  `id` int(11) NOT NULL,
  `query_text` varchar(255) NOT NULL,
  `total_search_count` int(11) DEFAULT 1,
  `click_through_rate` float DEFAULT 0,
  `avg_watch_time` float DEFAULT 0,
  `bounce_rate` float DEFAULT 0,
  `last_seen` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `search_queries`
--

INSERT INTO `search_queries` (`id`, `query_text`, `total_search_count`, `click_through_rate`, `avg_watch_time`, `bounce_rate`, `last_seen`) VALUES
(1, '#relatable', 1, 0, 0, 0, '2026-06-09 21:08:21'),
(2, '#so true', 2, 0, 0, 0, '2026-06-10 06:29:13'),
(3, '#love', 2, 0, 0, 0, '2026-06-10 06:29:02'),
(4, '#baddie', 1, 0, 0, 0, '2026-06-09 21:11:17'),
(5, '#dark', 1, 0, 0, 0, '2026-06-09 21:12:39'),
(6, 'a', 11, 0, 0, 0, '2026-07-22 09:58:54'),
(7, 'leo', 1, 0, 0, 0, '2026-06-10 06:00:36'),
(8, '#dark humor', 1, 0, 0, 0, '2026-06-10 06:01:58'),
(9, '#american', 1, 0, 0, 0, '2026-06-10 06:22:26'),
(10, 'cat memes', 2, 0, 0, 0, '2026-06-10 08:17:49'),
(13, 'mexico', 2, 0, 0, 0, '2026-06-10 06:32:22'),
(14, 'mexican', 1, 0, 0, 0, '2026-06-10 06:32:11'),
(16, 'tacos', 1, 0, 0, 0, '2026-06-10 06:33:00'),
(17, 'la liga', 2, 0, 0, 0, '2026-06-10 06:33:39'),
(19, 'laliga', 1, 0, 0, 0, '2026-06-10 06:33:53'),
(21, '#fyp', 1, 0, 0, 0, '2026-06-10 08:18:18'),
(26, 'caelix', 9, 0, 0, 0, '2026-07-07 11:21:36'),
(27, 'ca', 7, 0, 0, 0, '2026-07-07 19:49:58'),
(28, 'arie', 2, 0, 0, 0, '2026-07-02 15:55:35'),
(29, '#besties', 1, 0, 0, 0, '2026-06-18 06:42:49'),
(30, 'the wrold', 1, 0, 0, 0, '2026-06-18 07:12:51'),
(31, 'usa memes', 1, 0, 0, 0, '2026-06-18 07:31:20'),
(32, 'usa', 2, 0, 0, 0, '2026-06-18 07:32:30'),
(33, 'united states of america usa', 1, 0, 0, 0, '2026-06-18 07:32:07'),
(35, '#usa', 1, 0, 0, 0, '2026-06-18 07:34:25'),
(36, '#united states of america', 1, 0, 0, 0, '2026-06-18 07:35:08'),
(37, 'c', 2, 0, 0, 0, '2026-06-24 07:53:18'),
(39, 'ba', 1, 0, 0, 0, '2026-06-24 07:33:19'),
(40, 'chelsey', 6, 0, 0, 0, '2026-07-07 11:22:13'),
(50, 'tyven', 1, 0, 0, 0, '2026-06-24 07:49:44'),
(52, 'chill vibes', 1, 0, 0, 0, '2026-06-24 07:54:10'),
(53, 'l', 9, 0, 0, 0, '2026-07-15 07:42:33'),
(55, 'le', 1, 0, 0, 0, '2026-07-01 20:23:23'),
(59, 'claude', 1, 0, 0, 0, '2026-07-07 10:42:50'),
(60, 'chill', 1, 0, 0, 0, '2026-07-07 10:43:49'),
(61, 'pepe the frog', 1, 0, 0, 0, '2026-07-07 10:44:19'),
(62, 'im literally a baddie', 1, 0, 0, 0, '2026-07-07 10:47:17'),
(66, 'pepe the fro', 1, 0, 0, 0, '2026-07-07 11:07:31'),
(79, 'ho', 1, 0, 0, 0, '2026-07-07 19:50:26'),
(80, 'n', 1, 0, 0, 0, '2026-07-07 19:50:50'),
(82, 'dani', 2, 0, 0, 0, '2026-07-08 06:27:39'),
(83, 'b', 1, 0, 0, 0, '2026-07-07 20:15:36'),
(84, 'e', 6, 0, 0, 0, '2026-07-09 07:52:30'),
(95, 'd', 1, 0, 0, 0, '2026-07-31 13:06:12');

-- --------------------------------------------------------

--
-- Table structure for table `search_recents`
--

CREATE TABLE `search_recents` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `type` varchar(20) NOT NULL,
  `ref_id` varchar(100) NOT NULL,
  `data` text NOT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `search_recents`
--

INSERT INTO `search_recents` (`id`, `user_id`, `type`, `ref_id`, `data`, `updated_at`) VALUES
(2, 2, 'user', '8', '{\"type\":\"user\",\"id\":8,\"username\":\"Chelsey\",\"nickname\":\"Pretty Barbie\",\"profile_pic\":\"uploads\\/1776693705_Chelsey.jpeg\"}', '2026-06-10 09:16:29'),
(3, 2, 'user', '1', '{\"type\":\"user\",\"id\":1,\"username\":\"Tyven\",\"nickname\":\"Loverboy\",\"profile_pic\":\"uploads\\/1774069675_Jackson.jpg\"}', '2026-06-09 20:16:25'),
(5, 2, 'meme', '2', '{\"type\":\"meme\",\"id\":2,\"caption\":\"#Me and the family on a road trip\",\"file_name\":\"1774070108_1587.mp4\",\"meme_type\":\"video\",\"username\":\"Dani\",\"profile_pic\":\"uploads\\/1774069944_Dani.jpg\"}', '2026-06-09 20:16:42'),
(6, 2, 'meme', '85', '{\"type\":\"meme\",\"id\":85,\"caption\":\"What a funny year this week has been #Funny #memes #love\",\"file_name\":\"\",\"meme_type\":\"text\",\"username\":\"Tyven\",\"profile_pic\":\"uploads\\/1774069675_Jackson.jpg\"}', '2026-06-09 20:16:57'),
(7, 2, 'query', 'Y2E=', '{\"type\":\"query\",\"id\":\"Y2E=\",\"query\":\"ca\"}', '2026-06-09 20:17:07'),
(8, 2, 'user', '5', '{\"type\":\"user\",\"id\":5,\"username\":\"Armstrong\",\"nickname\":\"Funny-Lad\",\"profile_pic\":\"uploads\\/1774176847_profile.jpg.jpg\",\"is_following\":1,\"is_private\":0,\"is_blocked\":0,\"is_track_approved\":0}', '2026-06-18 07:33:25'),
(9, 2, 'meme', '40', '{\"type\":\"meme\",\"id\":40,\"caption\":\"My Face when something is suspicious\",\"file_name\":\"1774419027_6037.jpg\",\"meme_type\":\"image\",\"username\":\"Caelix\",\"profile_pic\":\"uploads\\/1774083646_Jaden.jpg\"}', '2026-06-09 20:41:33'),
(13, 2, 'query', 'JTIzcmVsYXRh', '{\"type\":\"query\",\"id\":\"JTIzcmVsYXRh\",\"query\":\"#relatable\"}', '2026-06-09 21:08:21'),
(21, 2, 'query', 'JTIzZGFyayUy', '{\"type\":\"query\",\"id\":\"JTIzZGFyayUy\",\"query\":\"#dark humor\"}', '2026-06-10 06:01:58'),
(23, 2, 'user', '7', '{\"type\":\"user\",\"id\":7,\"username\":\"Karem_88\",\"nickname\":\"Cake-Boss\",\"profile_pic\":\"uploads\\/1775709993_caelix.jpg\"}', '2026-06-10 08:17:15'),
(24, 2, 'meme', '3', '{\"type\":\"meme\",\"id\":3,\"caption\":\"when sickness lie finally catches up with you :) lol\",\"file_name\":\"1774070932_5579.jpg\",\"meme_type\":\"image\",\"username\":\"Leah\",\"profile_pic\":\"uploads\\/1774070831_leah.jpg\"}', '2026-06-10 06:28:22'),
(25, 2, 'query', 'Y2F0JTIwbWVt', '{\"type\":\"query\",\"id\":\"Y2F0JTIwbWVt\",\"query\":\"cat memes\"}', '2026-06-10 06:28:42'),
(35, 2, 'group', '1', '{\"type\":\"group\",\"id\":1,\"title\":\"Funny memes \",\"group_pic\":\"uploads\\/1774071374_hamster-with-big-eyes-meme-desktop-wallpaper-preview.jpg\",\"member_count\":6}', '2026-06-10 06:35:45'),
(37, 13, 'query', 'Q2F0JTIwbWVt', '{\"type\":\"query\",\"id\":\"Q2F0JTIwbWVt\",\"query\":\"Cat memes\"}', '2026-06-10 08:17:49'),
(38, 13, 'query', 'JTIzZnlw', '{\"type\":\"query\",\"id\":\"JTIzZnlw\",\"query\":\"#fyp\"}', '2026-06-10 08:18:18'),
(39, 13, 'meme', '85', '{\"type\":\"meme\",\"id\":85,\"caption\":\"What a funny year this week has been #Funny #memes #love\",\"file_name\":\"\",\"meme_type\":\"text\",\"username\":\"Tyven\",\"profile_pic\":\"uploads\\/1774069675_Jackson.jpg\"}', '2026-06-10 08:18:35'),
(40, 13, 'user', '7', '{\"type\":\"user\",\"id\":7,\"username\":\"Karem_88\",\"nickname\":\"Cake-Boss\",\"profile_pic\":\"uploads\\/1775709993_caelix.jpg\"}', '2026-06-10 08:19:36'),
(41, 13, 'group', '4', '{\"type\":\"group\",\"id\":4,\"title\":\"Bad Girls Club\",\"group_pic\":\"1776710119_WhatsApp Image 2026-04-20 at 17.46.30 (1).jpeg\",\"member_count\":4}', '2026-06-10 08:29:48'),
(42, 13, 'user', '3', '{\"type\":\"user\",\"id\":3,\"username\":\"Leah\",\"nickname\":\"That Girl\",\"profile_pic\":\"uploads\\/1774070831_leah.jpg\",\"is_following\":0,\"is_private\":1,\"is_blocked\":0,\"is_track_approved\":0}', '2026-06-10 11:37:57'),
(52, 2, 'query', 'YQ==', '{\"type\":\"query\",\"id\":\"YQ==\",\"query\":\"a\"}', '2026-06-10 10:12:35'),
(54, 13, 'user', '4', '{\"type\":\"user\",\"id\":4,\"username\":\"Caelix\",\"nickname\":\"Caelix Kole\",\"profile_pic\":\"uploads\\/1774083646_Jaden.jpg\",\"is_following\":0,\"is_private\":0,\"is_blocked\":1}', '2026-06-10 10:13:58'),
(58, 4, 'user', '8', '{\"type\":\"user\",\"id\":8,\"username\":\"Chelsey\",\"nickname\":\"Pretty Barbie\",\"profile_pic\":\"uploads\\/1776693705_Chelsey.jpeg\",\"is_following\":1,\"is_private\":0,\"is_blocked\":0,\"is_track_approved\":0}', '2026-06-10 13:15:21'),
(59, 4, 'group', '4', '{\"type\":\"group\",\"id\":4,\"title\":\"Bad Girls Club\",\"group_pic\":\"1776710119_WhatsApp Image 2026-04-20 at 17.46.30 (1).jpeg\",\"member_count\":4,\"is_joined\":0}', '2026-06-10 14:21:06'),
(60, 4, 'user', '1', '{\"type\":\"user\",\"id\":1,\"username\":\"Tyven\",\"nickname\":\"Loverboy\",\"profile_pic\":\"uploads\\/1774069675_Jackson.jpg\",\"is_following\":1,\"is_private\":0,\"is_blocked\":0,\"is_track_approved\":0}', '2026-06-11 09:05:58'),
(61, 1, 'user', '7', '{\"type\":\"user\",\"id\":7,\"username\":\"Karem_88\",\"nickname\":\"Cake-Boss\",\"profile_pic\":\"uploads\\/1775709993_caelix.jpg\",\"is_following\":1,\"is_private\":0,\"is_blocked\":0,\"is_track_approved\":0}', '2026-06-19 06:28:28'),
(62, 1, 'query', 'Y2FlbGl4', '{\"type\":\"query\",\"id\":\"Y2FlbGl4\",\"query\":\"caelix\"}', '2026-06-14 11:52:00'),
(64, 2, 'user', '16', '{\"type\":\"user\",\"id\":16,\"username\":\"Ariella\",\"nickname\":\"\",\"profile_pic\":\"uploads\\/1781591717_download (31).jpg\",\"is_following\":0,\"is_private\":0,\"is_blocked\":0,\"is_track_approved\":0}', '2026-06-16 07:47:43'),
(65, 1, 'query', 'QXJpZQ==', '{\"type\":\"query\",\"id\":\"QXJpZQ==\",\"query\":\"Arie\"}', '2026-07-02 15:55:35'),
(66, 1, 'user', '5', '{\"type\":\"user\",\"id\":5,\"username\":\"Armstrong\",\"nickname\":\"Funny-Lad\",\"profile_pic\":\"uploads\\/1774176847_profile.jpg.jpg\",\"is_following\":1,\"is_private\":0,\"is_blocked\":0,\"is_track_approved\":0}', '2026-06-19 06:38:49'),
(67, 1, 'group', '1', '{\"type\":\"group\",\"id\":1,\"title\":\"Funny memes \",\"group_pic\":\"uploads\\/1774071374_hamster-with-big-eyes-meme-desktop-wallpaper-preview.jpg\",\"member_count\":7,\"is_joined\":1}', '2026-07-02 15:55:29'),
(68, 3, 'memepedia', '1', '{\"type\":\"memepedia\",\"id\":1,\"title\":\"Cardi B\\u2019s \\u201cI Mean, Look\\u201d meme\",\"status\":\"trending\",\"category\":\"Video Memes\"}', '2026-06-18 07:01:31'),
(69, 3, 'collection', '4', '{\"type\":\"collection\",\"id\":4,\"name\":\"Cardi B memes\",\"owner_id\":3,\"owner_username\":\"Leah\",\"item_count\":3}', '2026-06-17 21:06:39'),
(71, 3, 'query', 'JTIzYmVzdGll', '{\"type\":\"query\",\"id\":\"JTIzYmVzdGll\",\"query\":\"#besties\"}', '2026-06-18 06:42:49'),
(72, 3, 'meme', '67', '{\"type\":\"meme\",\"id\":67,\"caption\":\"The World when is my turn to be an adult\",\"file_name\":\"0\",\"meme_type\":\"text\",\"username\":\"Chelsey\",\"profile_pic\":\"uploads\\/1776693705_Chelsey.jpeg\"}', '2026-06-18 06:43:43'),
(77, 2, 'meme', '48', '{\"type\":\"meme\",\"id\":48,\"caption\":\"[AD] [AD] Why do i feel so happy today lol\\u00a0\",\"file_name\":\"Text.jpg\",\"meme_type\":\"text\",\"username\":\"Caelix\",\"profile_pic\":\"uploads\\/1774083646_Jaden.jpg\"}', '2026-06-18 07:30:11'),
(83, 2, 'query', 'JTIzVVNB', '{\"type\":\"query\",\"id\":\"JTIzVVNB\",\"query\":\"#USA\"}', '2026-06-18 07:34:25'),
(84, 2, 'query', 'JTIzVW5pdGVk', '{\"type\":\"query\",\"id\":\"JTIzVW5pdGVk\",\"query\":\"#United States of America\"}', '2026-06-18 07:35:08'),
(87, 1, 'memepedia', '1', '{\"type\":\"memepedia\",\"id\":1,\"title\":\"Cardi B\\u2019s \\u201cI Mean, Look\\u201d meme\",\"status\":\"trending\",\"category\":\"Video Memes\"}', '2026-06-19 06:38:18'),
(90, 2, 'memepedia', '3', '{\"type\":\"memepedia\",\"id\":3,\"title\":\"Pepe the Frog\",\"status\":\"rising\",\"category\":\"Classic Internet\"}', '2026-06-22 13:22:04'),
(92, 3, 'group', '1', '{\"type\":\"group\",\"id\":1,\"title\":\"Funny memes \",\"group_pic\":\"uploads\\/1774071374_hamster-with-big-eyes-meme-desktop-wallpaper-preview.jpg\",\"member_count\":7,\"is_joined\":1}', '2026-06-23 18:26:27'),
(94, 3, 'profile_id', '7', '{\"type\":\"profile_id\",\"id\":7,\"username\":\"Karem_88\",\"nickname\":\"Cake-Boss\",\"profile_pic\":\"uploads\\/1775709993_caelix.jpg\",\"dz_id\":\"DZ-B1AC730AE\",\"is_following\":1,\"is_private\":0,\"is_blocked\":0,\"is_track_approved\":0}', '2026-06-24 05:38:42'),
(116, 3, 'group', '4', '{\"type\":\"group\",\"id\":4,\"title\":\"Bad Girls Club\",\"group_pic\":\"1776710119_WhatsApp Image 2026-04-20 at 17.46.30 (1).jpeg\",\"member_count\":6,\"is_joined\":1}', '2026-06-24 09:16:45'),
(117, 3, 'profile_id', '8', '{\"type\":\"profile_id\",\"id\":8,\"username\":\"Chelsey\",\"nickname\":\"Pretty Barbie\",\"profile_pic\":\"uploads\\/1776693705_Chelsey.jpeg\",\"dz_id\":\"DZ-2397B7ED0\",\"is_following\":1,\"is_private\":0,\"is_blocked\":0,\"is_track_approved\":0}', '2026-07-07 09:50:47'),
(120, 2, 'group', '4', '{\"type\":\"group\",\"id\":4,\"title\":\"Bad Girls Club\",\"group_pic\":\"1782636653_download (39).jpg\",\"member_count\":6,\"is_joined\":1,\"is_adult\":0,\"is_muted\":0,\"notif_off\":0}', '2026-07-01 12:50:48'),
(121, 8, 'group', '2', '{\"type\":\"group\",\"id\":2,\"title\":\"Cardi Fan page\",\"group_pic\":\"1782577754_download (35).jpg\",\"member_count\":8,\"is_joined\":1,\"is_adult\":1,\"is_muted\":0,\"notif_off\":0}', '2026-07-01 20:22:09'),
(122, 8, 'user', '1', '{\"type\":\"user\",\"id\":1,\"username\":\"Tyven\",\"nickname\":\"Loverboy\",\"profile_pic\":\"uploads\\/1774069675_Jackson.jpg\",\"dz_id\":\"DZ-10E34AFAD\",\"is_following\":1,\"is_private\":0,\"is_blocked\":0,\"is_track_approved\":0}', '2026-07-01 20:22:19'),
(123, 8, 'memepedia', '3', '{\"type\":\"memepedia\",\"id\":3,\"title\":\"Pepe the Frog\",\"status\":\"rising\",\"category\":\"Classic Internet\"}', '2026-07-01 20:22:50'),
(124, 8, 'query', 'bGU=', '{\"type\":\"query\",\"id\":\"bGU=\",\"query\":\"le\"}', '2026-07-01 20:23:23'),
(125, 8, 'sound_collection', '1', '{\"type\":\"sound_collection\",\"id\":1,\"name\":\"Chill Vibes\",\"owner_id\":3,\"owner_username\":\"Leah\",\"item_count\":2}', '2026-07-01 20:23:43'),
(126, 8, 'query', 'bA==', '{\"type\":\"query\",\"id\":\"bA==\",\"query\":\"l\"}', '2026-07-01 20:24:39'),
(127, 8, 'group', '6', '{\"type\":\"group\",\"id\":6,\"title\":\"Claude\",\"group_pic\":\"uploads\\/1782581418_Claude Logo PNG Vector (SVG) Free Download (1).jpg\",\"member_count\":5,\"is_joined\":1,\"is_adult\":0,\"is_muted\":0,\"notif_off\":0}', '2026-07-01 20:24:52'),
(128, 8, 'profile_id', '13', '{\"type\":\"profile_id\",\"id\":13,\"username\":\"Steven\",\"nickname\":\"Steven\",\"profile_pic\":\"uploads\\/profile_13_1782110432_2c65ee3d.jpg\",\"dz_id\":\"DZ-9C22581AD\",\"is_following\":1,\"is_private\":0,\"is_blocked\":0,\"is_track_approved\":0}', '2026-07-01 20:25:34'),
(131, 7, 'group', '1', '{\"type\":\"group\",\"id\":1,\"title\":\"Funny memes\",\"group_pic\":\"uploads\\/1774071374_hamster-with-big-eyes-meme-desktop-wallpaper-preview.jpg\",\"member_count\":8,\"is_joined\":1,\"is_adult\":0,\"is_muted\":0,\"notif_off\":0}', '2026-07-21 09:00:37'),
(132, 7, 'collection', '7', '{\"type\":\"collection\",\"id\":7,\"name\":\"Funny memes\",\"owner_id\":2,\"owner_username\":\"Dani\",\"item_count\":4}', '2026-07-05 08:08:53'),
(133, 2, 'group', '2', '{\"type\":\"group\",\"id\":2,\"title\":\"Cardi B Fan page\",\"group_pic\":\"1782577754_download (35).jpg\",\"member_count\":8,\"is_joined\":1,\"is_adult\":1,\"is_muted\":0,\"notif_off\":0}', '2026-07-05 14:56:57'),
(153, 3, 'user', '1', '{\"type\":\"user\",\"id\":1,\"username\":\"Tyven\",\"nickname\":\"Loverboy\",\"profile_pic\":\"uploads\\/1774069675_Jackson.jpg\",\"dz_id\":\"DZ-10E34AFAD\",\"is_following\":1,\"is_private\":0,\"is_blocked\":0,\"is_track_approved\":0}', '2026-07-07 11:29:27'),
(158, 3, 'memepedia', '2', '{\"type\":\"memepedia\",\"id\":2,\"title\":\"Topic: Viral Internet Memes \\\"Distracted Boyfriend\\\"\",\"status\":\"legendary\",\"category\":\"Image Memes\",\"media_url\":\"uploads\\/memepedia\\/meme_6a285c93ef7447.67006339.jpg\",\"media_type\":\"image\"}', '2026-07-07 19:28:18'),
(159, 3, 'memepedia', '3', '{\"type\":\"memepedia\",\"id\":3,\"title\":\"Pepe the Frog\",\"status\":\"rising\",\"category\":\"Classic Internet\",\"media_url\":\"uploads\\/memepedia\\/meme_6a285e549fddb3.03368806.jpg\",\"media_type\":\"image\"}', '2026-07-07 19:28:50'),
(160, 3, 'group', '2', '{\"type\":\"group\",\"id\":2,\"title\":\"Cardi B Fan page\",\"group_pic\":\"1782577754_download (35).jpg\",\"member_count\":8,\"is_joined\":1,\"is_adult\":1,\"is_muted\":0,\"notif_off\":0}', '2026-07-07 19:29:05'),
(161, 3, 'user', '7', '{\"type\":\"user\",\"id\":7,\"username\":\"Karem_88\",\"nickname\":\"Cake-Boss\",\"profile_pic\":\"uploads\\/1775709993_caelix.jpg\",\"dz_id\":\"DZ-B1AC730AE\",\"is_following\":1,\"is_private\":0,\"is_blocked\":0,\"is_track_approved\":0}', '2026-07-07 19:30:34'),
(176, 3, 'query', 'ZQ==', '{\"type\":\"query\",\"id\":\"ZQ==\",\"query\":\"e\"}', '2026-07-09 07:52:30'),
(178, 2, 'query', 'bA==', '{\"type\":\"query\",\"id\":\"bA==\",\"query\":\"l\"}', '2026-07-15 07:42:33'),
(179, 16, 'user', '7', '{\"type\":\"user\",\"id\":7,\"username\":\"Karem_88\",\"nickname\":\"Cake-Boss\",\"profile_pic\":\"uploads\\/1775709993_caelix.jpg\",\"dz_id\":\"DZ-B1AC730AE\",\"is_following\":1,\"is_private\":0,\"is_blocked\":0,\"is_track_approved\":0,\"preview_posts\":[],\"bio\":\"Born in the Middle East, building for the world.\\nTech enthusiast with a passion for turning ideas into products that actually matter. I live at the intersection of culture and code \\u2014 where tradition meets innovation.\\nAlways learning, always building, never settling.\"}', '2026-07-15 11:05:17'),
(181, 7, 'query', 'YQ==', '{\"type\":\"query\",\"id\":\"YQ==\",\"query\":\"a\"}', '2026-07-22 09:58:54'),
(182, 7, 'group', '7', '{\"type\":\"group\",\"id\":7,\"title\":\"Cat Internet\",\"group_pic\":\"pod_6a5f74d9a15b2.jpg\",\"member_count\":2,\"is_joined\":1,\"is_adult\":0,\"is_muted\":0,\"notif_off\":0,\"owner_id\":7,\"owner_username\":\"Karem_88\",\"preview_posts\":[]}', '2026-07-22 10:00:20'),
(183, 7, 'user', '19', '{\"type\":\"user\",\"id\":19,\"username\":\"Izel\",\"nickname\":\"\",\"profile_pic\":\"uploads\\/me.png\",\"dz_id\":\"DZ-768301\",\"is_following\":0,\"is_private\":0,\"is_blocked\":0,\"is_track_approved\":0,\"preview_posts\":[],\"bio\":\"\"}', '2026-07-31 13:05:11'),
(192, 7, 'query', 'ZA==', '{\"type\":\"query\",\"id\":\"ZA==\",\"query\":\"d\"}', '2026-07-31 13:06:12'),
(193, 19, 'user', '3', '{\"type\":\"user\",\"id\":3,\"username\":\"Leah\",\"nickname\":\"That Girl\",\"profile_pic\":\"uploads\\/1774070831_leah.jpg\",\"dz_id\":\"DZ-3483E5517\",\"is_following\":0,\"is_private\":1,\"is_blocked\":0,\"is_track_approved\":0,\"preview_posts\":[{\"id\":137,\"file_name\":\"1785338117_6a6a190566277_0.jpg\",\"type\":\"image\",\"caption\":\"# i love me some cats thank you\"},{\"id\":66,\"file_name\":\"1776502286_7229.jpg\",\"type\":\"image\",\"caption\":\" My face when i hear rubbish \\ud83e\\udd23\\ud83e\\udd23 #funny\"},{\"id\":38,\"file_name\":\"1774418756_6623.jpg\",\"type\":\"image\",\"caption\":\"when you the only serious one in the group\"}],\"bio\":\"\"}', '2026-08-01 10:44:40');

-- --------------------------------------------------------

--
-- Table structure for table `search_trending`
--

CREATE TABLE `search_trending` (
  `id` int(11) NOT NULL,
  `query_text` varchar(255) NOT NULL,
  `velocity_score` float DEFAULT 0,
  `time_window` enum('1h','24h','7d') DEFAULT '24h',
  `spike_flag` tinyint(4) DEFAULT 0,
  `updated_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `search_trending`
--

INSERT INTO `search_trending` (`id`, `query_text`, `velocity_score`, `time_window`, `spike_flag`, `updated_at`) VALUES
(1, '#relatable', 1, '1h', 0, '2026-06-09 21:08:21'),
(2, '#so true', 1, '1h', 0, '2026-06-10 06:29:13'),
(3, '#love', 1, '1h', 0, '2026-06-10 06:29:02'),
(4, '#baddie', 1, '1h', 0, '2026-06-09 21:11:17'),
(5, '#dark', 1, '1h', 0, '2026-06-09 21:12:39'),
(6, 'a', 1, '1h', 0, '2026-07-22 09:58:54'),
(7, 'a', 1, '24h', 0, '2026-06-10 05:40:47'),
(8, 'leo', 1, '1h', 0, '2026-06-10 06:00:36'),
(9, 'leo', 1, '24h', 0, '2026-06-10 06:00:36'),
(10, '#dark humor', 1, '1h', 0, '2026-06-10 06:01:58'),
(11, '#american', 1, '1h', 0, '2026-06-10 06:22:26'),
(12, 'cat memes', 1, '1h', 0, '2026-06-10 08:17:49'),
(15, 'mexico', 2, '1h', 0, '2026-06-10 06:32:22'),
(16, 'mexican', 1, '1h', 0, '2026-06-10 06:32:11'),
(18, 'tacos', 1, '1h', 0, '2026-06-10 06:33:00'),
(19, 'la liga', 2, '1h', 0, '2026-06-10 06:33:39'),
(21, 'laliga', 1, '1h', 0, '2026-06-10 06:33:53'),
(23, '#fyp', 1, '1h', 0, '2026-06-10 08:18:18'),
(28, 'caelix', 2, '1h', 0, '2026-07-07 11:21:36'),
(29, 'ca', 1, '1h', 0, '2026-07-07 19:49:58'),
(30, 'arie', 1, '1h', 0, '2026-07-02 15:55:35'),
(31, '#besties', 1, '1h', 0, '2026-06-18 06:42:49'),
(32, 'the wrold', 1, '1h', 0, '2026-06-18 07:12:51'),
(33, 'usa memes', 1, '1h', 0, '2026-06-18 07:31:20'),
(34, 'usa', 2, '1h', 0, '2026-06-18 07:32:30'),
(35, 'united states of america usa', 1, '1h', 0, '2026-06-18 07:32:07'),
(37, '#usa', 1, '1h', 0, '2026-06-18 07:34:25'),
(38, '#united states of america', 1, '1h', 0, '2026-06-18 07:35:08'),
(39, 'c', 1, '1h', 0, '2026-06-24 07:53:18'),
(41, 'ba', 1, '1h', 0, '2026-06-24 07:33:19'),
(42, 'chelsey', 1, '1h', 0, '2026-07-07 11:22:13'),
(52, 'tyven', 1, '1h', 0, '2026-06-24 07:49:44'),
(54, 'chill vibes', 1, '1h', 0, '2026-06-24 07:54:10'),
(55, 'l', 1, '1h', 0, '2026-07-15 07:42:33'),
(57, 'le', 1, '1h', 0, '2026-07-01 20:23:23'),
(61, 'claude', 1, '1h', 0, '2026-07-07 10:42:50'),
(62, 'chill', 1, '1h', 0, '2026-07-07 10:43:49'),
(63, 'pepe the frog', 1, '1h', 0, '2026-07-07 10:44:19'),
(64, 'im literally a baddie', 1, '1h', 0, '2026-07-07 10:47:17'),
(68, 'pepe the fro', 1, '1h', 0, '2026-07-07 11:07:31'),
(81, 'ho', 1, '1h', 0, '2026-07-07 19:50:26'),
(82, 'n', 1, '1h', 0, '2026-07-07 19:50:50'),
(84, 'dani', 1, '1h', 0, '2026-07-08 06:27:39'),
(85, 'b', 1, '1h', 0, '2026-07-07 20:15:36'),
(86, 'e', 1, '1h', 0, '2026-07-09 07:52:30'),
(97, 'd', 1, '1h', 0, '2026-07-31 13:06:12');

-- --------------------------------------------------------

--
-- Table structure for table `search_user_signals`
--

CREATE TABLE `search_user_signals` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `signal_type` enum('search','click','like','save','watch') NOT NULL,
  `signal_value` varchar(255) NOT NULL,
  `weight` float DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `search_user_signals`
--

INSERT INTO `search_user_signals` (`id`, `user_id`, `signal_type`, `signal_value`, `weight`, `created_at`) VALUES
(1, 2, 'search', '#relatable', 1, '2026-06-09 21:08:21'),
(2, 2, 'search', '#so true', 1, '2026-06-09 21:08:43'),
(3, 2, 'search', '#love', 1, '2026-06-09 21:08:57'),
(4, 2, 'search', '#baddie', 1, '2026-06-09 21:11:17'),
(5, 2, 'search', '#dark', 1, '2026-06-09 21:12:39'),
(6, 2, 'search', 'a', 1, '2026-06-10 05:40:47'),
(7, 2, 'search', 'leo', 1, '2026-06-10 06:00:36'),
(8, 2, 'search', '#dark humor', 1, '2026-06-10 06:01:58'),
(9, 2, 'search', '#american', 1, '2026-06-10 06:22:26'),
(10, 2, 'search', 'cat memes', 1, '2026-06-10 06:28:42'),
(11, 2, 'search', '#love', 1, '2026-06-10 06:29:02'),
(12, 2, 'search', '#so true', 1, '2026-06-10 06:29:13'),
(13, 2, 'search', 'mexico', 1, '2026-06-10 06:31:56'),
(14, 2, 'search', 'mexican', 1, '2026-06-10 06:32:11'),
(15, 2, 'search', 'mexico', 1, '2026-06-10 06:32:22'),
(16, 2, 'search', 'tacos', 1, '2026-06-10 06:33:00'),
(17, 2, 'search', 'la liga', 1, '2026-06-10 06:33:29'),
(18, 2, 'search', 'la liga', 1, '2026-06-10 06:33:39'),
(19, 2, 'search', 'laliga', 1, '2026-06-10 06:33:53'),
(20, 13, 'search', 'cat memes', 1, '2026-06-10 08:17:49'),
(21, 13, 'search', '#fyp', 1, '2026-06-10 08:18:18'),
(22, 2, 'search', 'a', 1, '2026-06-10 10:12:35'),
(23, 13, 'search', 'a', 1, '2026-06-10 10:13:06'),
(24, 13, 'search', 'a', 1, '2026-06-10 11:43:41'),
(25, 13, 'search', 'a', 1, '2026-06-10 11:44:27'),
(26, 1, 'search', 'caelix', 1, '2026-06-14 11:52:00'),
(27, 1, 'search', 'ca', 1, '2026-06-14 11:52:12'),
(28, 1, 'search', 'arie', 1, '2026-06-16 18:06:35'),
(29, 3, 'search', '#besties', 1, '2026-06-18 06:42:49'),
(30, 3, 'search', 'the wrold', 1, '2026-06-18 07:12:51'),
(31, 2, 'search', 'usa memes', 1, '2026-06-18 07:31:20'),
(32, 2, 'search', 'usa', 1, '2026-06-18 07:31:39'),
(33, 2, 'search', 'united states of america usa', 1, '2026-06-18 07:32:07'),
(34, 2, 'search', 'usa', 1, '2026-06-18 07:32:30'),
(35, 2, 'search', '#usa', 1, '2026-06-18 07:34:25'),
(36, 2, 'search', '#united states of america', 1, '2026-06-18 07:35:08'),
(37, 3, 'search', 'c', 1, '2026-06-24 06:05:33'),
(38, 3, 'search', 'caelix', 1, '2026-06-24 07:17:56'),
(39, 3, 'search', 'ba', 1, '2026-06-24 07:33:19'),
(40, 3, 'search', 'chelsey', 1, '2026-06-24 07:34:03'),
(41, 3, 'search', 'chelsey', 1, '2026-06-24 07:34:03'),
(42, 3, 'search', 'chelsey', 1, '2026-06-24 07:34:11'),
(43, 3, 'search', 'caelix', 1, '2026-06-24 07:35:01'),
(44, 3, 'search', 'caelix', 1, '2026-06-24 07:35:01'),
(45, 3, 'search', 'caelix', 1, '2026-06-24 07:35:21'),
(46, 3, 'search', 'caelix', 1, '2026-06-24 07:41:53'),
(47, 3, 'search', 'chelsey', 1, '2026-06-24 07:42:13'),
(48, 3, 'search', 'chelsey', 1, '2026-06-24 07:42:13'),
(49, 3, 'search', 'caelix', 1, '2026-06-24 07:49:14'),
(50, 3, 'search', 'tyven', 1, '2026-06-24 07:49:44'),
(51, 3, 'search', 'c', 1, '2026-06-24 07:53:18'),
(52, 3, 'search', 'chill vibes', 1, '2026-06-24 07:54:10'),
(53, 3, 'search', 'l', 1, '2026-06-24 08:45:51'),
(54, 3, 'search', 'l', 1, '2026-06-24 09:03:39'),
(55, 8, 'search', 'le', 1, '2026-07-01 20:23:23'),
(56, 8, 'search', 'l', 1, '2026-07-01 20:24:39'),
(57, 1, 'search', 'arie', 1, '2026-07-02 15:55:35'),
(58, 3, 'search', 'caelix', 1, '2026-07-07 10:36:36'),
(59, 3, 'search', 'claude', 1, '2026-07-07 10:42:50'),
(60, 3, 'search', 'chill', 1, '2026-07-07 10:43:49'),
(61, 3, 'search', 'pepe the frog', 1, '2026-07-07 10:44:19'),
(62, 3, 'search', 'im literally a baddie', 1, '2026-07-07 10:47:17'),
(63, 3, 'search', 'ca', 1, '2026-07-07 10:49:19'),
(64, 3, 'search', 'ca', 1, '2026-07-07 11:07:00'),
(65, 3, 'search', 'ca', 1, '2026-07-07 11:07:11'),
(66, 3, 'search', 'pepe the fro', 1, '2026-07-07 11:07:31'),
(67, 3, 'search', 'caelix', 1, '2026-07-07 11:21:36'),
(68, 3, 'search', 'chelsey', 1, '2026-07-07 11:22:13'),
(69, 3, 'search', 'a', 1, '2026-07-07 11:22:37'),
(70, 3, 'search', 'l', 1, '2026-07-07 11:25:10'),
(71, 3, 'search', 'l', 1, '2026-07-07 11:25:48'),
(72, 3, 'search', 'l', 1, '2026-07-07 11:26:18'),
(73, 3, 'search', 'a', 1, '2026-07-07 11:27:57'),
(74, 3, 'search', 'ca', 1, '2026-07-07 12:29:10'),
(75, 3, 'search', 'ca', 1, '2026-07-07 12:36:23'),
(76, 3, 'search', 'a', 1, '2026-07-07 18:56:35'),
(77, 3, 'search', 'l', 1, '2026-07-07 19:42:11'),
(78, 3, 'search', 'ca', 1, '2026-07-07 19:49:58'),
(79, 3, 'search', 'ho', 1, '2026-07-07 19:50:26'),
(80, 3, 'search', 'n', 1, '2026-07-07 19:50:50'),
(81, 3, 'search', 'a', 1, '2026-07-07 19:56:12'),
(82, 3, 'search', 'dani', 1, '2026-07-07 19:58:24'),
(83, 3, 'search', 'b', 1, '2026-07-07 20:15:36'),
(84, 3, 'search', 'e', 1, '2026-07-07 20:33:38'),
(85, 3, 'search', 'e', 1, '2026-07-08 04:34:11'),
(86, 3, 'search', 'l', 1, '2026-07-08 04:45:38'),
(87, 3, 'search', 'a', 1, '2026-07-08 04:57:43'),
(88, 3, 'search', 'e', 1, '2026-07-08 05:18:33'),
(89, 3, 'search', 'e', 1, '2026-07-08 06:04:34'),
(90, 3, 'search', 'dani', 1, '2026-07-08 06:27:39'),
(91, 3, 'search', 'e', 1, '2026-07-08 08:24:06'),
(92, 3, 'search', 'e', 1, '2026-07-09 07:52:30'),
(93, 2, 'search', 'l', 1, '2026-07-15 07:42:33'),
(94, 7, 'search', 'a', 1, '2026-07-22 09:58:54'),
(95, 7, 'search', 'd', 1, '2026-07-31 13:06:12');

-- --------------------------------------------------------

--
-- Table structure for table `sounds`
--

CREATE TABLE `sounds` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `original_user_id` int(10) UNSIGNED DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sounds`
--

INSERT INTO `sounds` (`id`, `user_id`, `original_user_id`, `title`, `file_name`, `created_at`) VALUES
(4, 3, 3, 'lol', '1775356090_69d1c8ba9476a.mp3', '2026-04-05 02:28:10'),
(12, 1, 1, 'My First', '1775359554_69d1d642064ac.mp3', '2026-04-05 03:25:54');

-- --------------------------------------------------------

--
-- Table structure for table `sound_folders`
--

CREATE TABLE `sound_folders` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `name` varchar(80) NOT NULL,
  `is_public` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `sound_folders`
--

INSERT INTO `sound_folders` (`id`, `user_id`, `name`, `is_public`, `created_at`) VALUES
(1, 3, 'Chill Vibes', 1, '2026-05-29 09:55:32');

-- --------------------------------------------------------

--
-- Table structure for table `sound_folder_items`
--

CREATE TABLE `sound_folder_items` (
  `id` int(11) NOT NULL,
  `folder_id` int(11) NOT NULL,
  `sound_id` int(11) NOT NULL,
  `added_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `sound_folder_items`
--

INSERT INTO `sound_folder_items` (`id`, `folder_id`, `sound_id`, `added_at`) VALUES
(1, 1, 4, '2026-05-29 09:56:11'),
(2, 1, 12, '2026-05-29 09:56:19');

-- --------------------------------------------------------

--
-- Table structure for table `sponsorships`
--

CREATE TABLE `sponsorships` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `title` varchar(100) NOT NULL,
  `image` varchar(255) DEFAULT NULL,
  `meme_image` varchar(255) DEFAULT NULL,
  `caption` varchar(255) DEFAULT NULL,
  `link` varchar(255) NOT NULL,
  `contact_info` varchar(150) NOT NULL,
  `type` enum('Standard','Premium','Exclusive VIP') DEFAULT 'Standard',
  `price` decimal(10,2) NOT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sponsorships`
--

INSERT INTO `sponsorships` (`id`, `user_id`, `title`, `image`, `meme_image`, `caption`, `link`, `contact_info`, `type`, `price`, `status`, `created_at`) VALUES
(6, 2, 'inaa', 'logo_1775139341_photo_2026_03_21_10_27_01.jpg', 'media_1775139341_video__2_.mp4', 'love lol', 'https://www.instagram.com/?hl=en', 'azielnerall@gmail.com | 08103733649', 'Standard', 49.00, 'approved', '2026-04-02 14:15:41'),
(7, 2, 'lan', 'logo_1775146776_photo_2026_03_21_10_26_25.jpg', 'media_1775146776_video.mp4', 'my happy face in a group :)', 'https://www.instagram.com/?hl=en', 'azielnerall@gmail.com | 08103733649', 'Exclusive VIP', 499.00, 'approved', '2026-04-02 16:19:36'),
(10, 2, 'olm', 'logo_1775147151_photo_2026_03_21_10_26_56.jpg', 'media_1775147151_photo_2026_03_21_10_26_56.jpg', 'my happy face in a group :)', 'https://www.instagram.com/?hl=en', 'azielnerall@gmail.com | 08103733649', 'Premium', 149.00, 'approved', '2026-04-02 16:25:51'),
(12, 3, 'jetbrains', 'logo_1776584590_Peura.jpg', 'media_1776584590_photo_2026_03_21_10_26_34.jpg', 'feel the jets in my brains ❤️❤️', 'https://www.jetbrains.com/phpstorm/download/download-thanks.html?platform=windows', 'azielnerall@gmail.com | 08103733649', 'Premium', 149.00, 'approved', '2026-04-19 07:43:10'),
(13, 3, 'Neural', 'logo_1776584711_photo_2026_03_21_10_26_51.jpg', 'media_1776584711_photo_2026_03_21_10_26_31.jpg', 'i love my on peace check neural', 'https://www.nba.com/', 'azielnerall@gmail.com | 08103733649', 'Standard', 49.00, 'approved', '2026-04-19 07:45:11');

-- --------------------------------------------------------

--
-- Table structure for table `subscriptions`
--

CREATE TABLE `subscriptions` (
  `id` int(10) UNSIGNED NOT NULL,
  `owner_id` int(10) UNSIGNED NOT NULL,
  `subscriber_id` int(10) UNSIGNED NOT NULL,
  `username` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `phone` varchar(30) NOT NULL,
  `receipt` varchar(255) NOT NULL,
  `approved` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `tier_id` int(11) NOT NULL,
  `status` enum('pending','approved','declined') DEFAULT 'pending'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `subscriptions`
--

INSERT INTO `subscriptions` (`id`, `owner_id`, `subscriber_id`, `username`, `email`, `phone`, `receipt`, `approved`, `created_at`, `tier_id`, `status`) VALUES
(3, 4, 3, 'leah', 'azielnerall@gmail.com', '08103733649', 'rcpt_1775379843_69d22583e5cc2.png', 1, '2026-04-05 09:04:03', 0, 'pending');

-- --------------------------------------------------------

--
-- Table structure for table `subscription_content`
--

CREATE TABLE `subscription_content` (
  `id` int(10) UNSIGNED NOT NULL,
  `owner_id` int(10) UNSIGNED NOT NULL,
  `content_desc` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `subscription_content`
--

INSERT INTO `subscription_content` (`id`, `owner_id`, `content_desc`) VALUES
(1, 4, 'This page is exclusive memes of me if you\'re interested :)');

-- --------------------------------------------------------

--
-- Table structure for table `subscription_memes`
--

CREATE TABLE `subscription_memes` (
  `id` int(10) UNSIGNED NOT NULL,
  `owner_id` int(10) UNSIGNED NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `tier_id` int(11) NOT NULL DEFAULT 0,
  `caption` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `subscription_memes`
--

INSERT INTO `subscription_memes` (`id`, `owner_id`, `file_name`, `created_at`, `tier_id`, `caption`) VALUES
(2, 4, 'meme_1775380321_69d227611a384.png', '2026-04-05 09:12:01', 0, 'lol i love this');

-- --------------------------------------------------------

--
-- Table structure for table `subscription_tiers`
--

CREATE TABLE `subscription_tiers` (
  `id` int(10) UNSIGNED NOT NULL,
  `owner_id` int(10) UNSIGNED NOT NULL,
  `tier_name` varchar(100) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `subscription_tiers`
--

INSERT INTO `subscription_tiers` (`id`, `owner_id`, `tier_name`, `price`, `created_at`) VALUES
(2, 4, 'standard memes', 250.00, '2026-04-05 07:55:01'),
(3, 4, 'premium memes', 500.00, '2026-04-05 07:55:39'),
(6, 4, 'premium memes', 900.00, '2026-04-05 08:14:47');

-- --------------------------------------------------------

--
-- Table structure for table `track_requests`
--

CREATE TABLE `track_requests` (
  `id` int(11) NOT NULL,
  `requester_id` int(11) NOT NULL,
  `target_id` int(11) NOT NULL,
  `status` enum('pending','approved','denied') DEFAULT 'pending',
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `track_requests`
--

INSERT INTO `track_requests` (`id`, `requester_id`, `target_id`, `status`, `created_at`) VALUES
(9, 8, 3, 'approved', '2026-05-29 13:43:57'),
(11, 4, 3, 'approved', '2026-05-29 13:47:08'),
(12, 1, 3, 'approved', '2026-05-29 14:52:01'),
(14, 5, 3, 'approved', '2026-05-29 17:58:54'),
(21, 2, 3, 'approved', '2026-06-07 20:03:11'),
(24, 16, 3, 'approved', '2026-06-16 09:09:32'),
(31, 13, 3, 'approved', '2026-06-27 07:24:02'),
(32, 7, 3, 'approved', '2026-07-23 11:40:02');

-- --------------------------------------------------------

--
-- Table structure for table `unfollow_log`
--

CREATE TABLE `unfollow_log` (
  `id` int(10) UNSIGNED NOT NULL,
  `unfollowed_by` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `unfollowed_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `unfollow_log`
--

INSERT INTO `unfollow_log` (`id`, `unfollowed_by`, `user_id`, `unfollowed_at`) VALUES
(15, 3, 13, '2026-05-22 21:36:04'),
(18, 4, 3, '2026-05-29 11:44:10'),
(19, 4, 3, '2026-05-29 12:08:19'),
(20, 4, 3, '2026-05-29 12:20:44'),
(21, 4, 3, '2026-05-29 12:20:47'),
(22, 4, 3, '2026-05-29 12:21:36'),
(23, 4, 3, '2026-05-29 12:21:38'),
(24, 4, 3, '2026-05-29 12:22:23'),
(25, 4, 3, '2026-05-29 12:22:32'),
(26, 4, 3, '2026-05-29 13:25:08'),
(28, 4, 3, '2026-05-29 13:31:43'),
(29, 4, 3, '2026-05-29 13:42:34'),
(30, 8, 3, '2026-05-29 13:43:54'),
(31, 4, 3, '2026-05-29 13:46:58'),
(32, 1, 3, '2026-05-29 14:51:24'),
(33, 5, 3, '2026-05-29 16:43:59'),
(34, 5, 3, '2026-05-29 17:57:38'),
(42, 7, 3, '2026-05-30 07:15:39'),
(44, 2, 3, '2026-05-30 19:29:07'),
(49, 2, 3, '2026-06-07 19:56:52'),
(63, 16, 3, '2026-06-16 09:09:26'),
(94, 7, 3, '2026-07-23 11:36:17');

-- --------------------------------------------------------

--
-- Table structure for table `username_history`
--

CREATE TABLE `username_history` (
  `id` int(11) NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `old_username` varchar(50) NOT NULL,
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `username_history`
--

INSERT INTO `username_history` (`id`, `user_id`, `old_username`, `changed_at`) VALUES
(1, 3, 'Leah', '2026-06-16 08:51:44'),
(2, 3, 'Leya', '2026-06-16 08:54:23'),
(3, 13, 'Steven', '2026-06-22 05:15:37'),
(4, 13, 'Steven77', '2026-06-22 05:15:58'),
(5, 2, 'Dani', '2026-07-05 07:17:01'),
(6, 2, 'Daniel', '2026-07-05 13:49:10');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(10) UNSIGNED NOT NULL,
  `dz_id` varchar(15) DEFAULT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `profile_pic` varchar(255) DEFAULT 'uploads/me.png',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_active` datetime NOT NULL DEFAULT current_timestamp(),
  `user_rank` varchar(60) NOT NULL DEFAULT 'Regular',
  `nickname` varchar(50) DEFAULT NULL,
  `last_seen` datetime DEFAULT current_timestamp(),
  `cover_photo` varchar(255) DEFAULT NULL,
  `email_verified` tinyint(1) NOT NULL DEFAULT 0,
  `is_page` tinyint(1) NOT NULL DEFAULT 0,
  `banner_pic` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `dz_id`, `username`, `email`, `password`, `phone`, `profile_pic`, `created_at`, `last_active`, `user_rank`, `nickname`, `last_seen`, `cover_photo`, `email_verified`, `is_page`, `banner_pic`) VALUES
(1, 'DZ-10E34AFAD', 'Tyven', 'azielnerall@gmail.com', '$2y$10$zQkLeQuBl6cJvNUNL5218uThukaSMjS3unU0UUcEK53oaU6kNLisa', '08103733649', 'uploads/1774069675_Jackson.jpg', '2026-03-20 18:53:07', '2026-07-05 13:53:07', 'Admin', 'Loverboy', '2026-06-02 12:52:12', NULL, 1, 0, NULL),
(2, 'DZ-BECD3B8D6', 'Dani', 'zyeirmyrren@gmail.com', '$2y$10$umtHPyhTzCMo2zuvUCYfFeLqkdYiceL7UMvNn3NSx2aHpPZxXbx7a', '', 'uploads/1774069944_Dani.jpg', '2026-03-21 05:11:55', '2026-07-19 09:44:52', 'Regular', 'Starboy', '2026-06-02 12:52:12', NULL, 1, 0, NULL),
(3, 'DZ-3483E5517', 'Leah', 'geomarlin8@gmail.com', '$2y$10$N1z5nwHItntVyLuOy0aF9.hoHPKOkHU7ebiLUrunMQ1k9/m9ul.Ma', '', 'uploads/1774070831_leah.jpg', '2026-03-21 05:26:07', '2026-08-01 13:19:18', 'admin', 'That Girl', '2026-06-02 12:52:12', 'uploads/covers/cover_3.jpg', 1, 0, 'uploads/banner_3_1783613826_3668eef7.jpg'),
(4, 'DZ-D716B679C', 'Caelix', 'tyvensylos@gmail.com', '$2y$10$G4x2Q2M1VA2YH/4xcDW6Oepajr.yqnYEeGpJ3DH02pxlTcqolIxKa', '', 'uploads/1774083646_Jaden.jpg', '2026-03-21 08:59:37', '2026-07-16 10:07:23', 'Regular', 'Caelix Rivera', '2026-06-02 12:52:12', NULL, 1, 0, NULL),
(5, 'DZ-81935676E', 'Armstrong', 'jasperhayes156@gmail.com', '$2y$10$w7jdur8Df6HFn4juubifcuDmTqpnHcN.PVrvTtk4HnrXSDvh6vDMS', '', 'uploads/1774176847_profile.jpg.jpg', '2026-03-22 10:51:35', '2026-07-19 09:50:02', 'Regular', 'Funny-Lad', '2026-06-02 12:52:12', NULL, 1, 0, NULL),
(7, 'DZ-B1AC730AE', 'Karem_88', 'armstrongovie020907@gmail.com', '$2y$10$ywQl1TUrGay0LrVdcNpBBO8ukFk6QbQMmi4pX3Vuy4FMFP4R8xiHe', '', 'uploads/1775709993_caelix.jpg', '2026-04-09 04:45:12', '2026-08-01 15:47:46', 'Regular', 'Cake-Boss', '2026-06-02 12:52:12', NULL, 1, 0, 'uploads/banner_7_1783610378_60261937.jpg'),
(8, 'DZ-2397B7ED0', 'Chelsey', 'chelseeee37@gmail.com', '$2y$10$SioE6kUAQ9qVKfQOlEslHOriOQF3.BGDdVCbiejKhAFCRQo6/htkq', '08103733649', 'uploads/1776693705_Chelsey.jpeg', '2026-04-20 12:06:23', '2026-07-05 14:12:27', 'Regular', 'Pretty Barbie', '2026-06-02 12:52:12', NULL, 1, 0, NULL),
(13, 'DZ-9C22581AD', 'Steven', 'zimel9337@gmail.com', '$2y$10$Tf8HLKknVAN/q3ryGwaY0urZ6nUcIDD4PGZQTYJyxAxNOLDziQa3C', '', 'uploads/profile_13_1782110432_2c65ee3d.jpg', '2026-05-18 13:07:06', '2026-07-19 09:48:16', 'Regular', 'Steven', '2026-06-02 12:52:12', NULL, 1, 0, NULL),
(16, 'DZ-C37CD08BA', 'Ariella', 'cherrywod23@gmail.com', '$2y$10$sbcrU76omHer6UIQbmlM6esIi5OYx0f9r5RPAuOUPC/k/UooUrAMK', 'Ariella', 'uploads/1781591717_download (31).jpg', '2026-06-16 06:27:43', '2026-07-19 09:55:08', 'Regular', NULL, '2026-06-16 07:27:43', NULL, 0, 0, NULL),
(19, 'DZ-768301', 'Izel', 'jaydafe03@gmail.com', '$2y$10$Lftm4jquX9tEFhv4FSGuCuCpvSVdByGfKmfjqR/p8Zg4APuD2ci6G', '', 'uploads/me.png', '2026-07-25 06:40:35', '2026-08-01 11:21:39', 'Regular', NULL, '2026-07-25 07:40:35', NULL, 0, 0, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `user_about`
--

CREATE TABLE `user_about` (
  `user_id` int(10) UNSIGNED NOT NULL,
  `full_name` varchar(100) DEFAULT NULL,
  `nickname` varchar(50) DEFAULT NULL,
  `occupation` varchar(100) DEFAULT NULL,
  `education` varchar(150) DEFAULT NULL,
  `current_location` varchar(100) DEFAULT NULL,
  `hometown` varchar(100) DEFAULT NULL,
  `relationship_status` varchar(30) DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `website` varchar(255) DEFAULT NULL,
  `contact_email` varchar(150) DEFAULT NULL,
  `contact_phone` varchar(30) DEFAULT NULL,
  `political_views` varchar(100) DEFAULT NULL,
  `religious_views` varchar(100) DEFAULT NULL,
  `favorite_quotes` text DEFAULT NULL,
  `interests` text DEFAULT NULL,
  `description` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_about`
--

INSERT INTO `user_about` (`user_id`, `full_name`, `nickname`, `occupation`, `education`, `current_location`, `hometown`, `relationship_status`, `date_of_birth`, `website`, `contact_email`, `contact_phone`, `political_views`, `religious_views`, `favorite_quotes`, `interests`, `description`, `updated_at`) VALUES
(1, 'Tyven J. Corbin', 'Ty', 'Content Creator, Market Analyst and Fitness Coach', 'Business Admin', 'Osaka,  Japan', 'Seattle, Washington, USA', 'Single', '1997-10-26', NULL, NULL, '+1 (555) 483-2197', 'Moderate', 'Agnostic', '\"Details create the experience.\"', 'Design, Internet Culture, Technology, Gaming, Photography, Travel', 'I\'m a designer who enjoys creating digital experiences that feel simple, intuitive, and memorable. Most of my free time is spent exploring internet culture, collecting design inspiration, discovering new technology, and getting lost in random rabbit holes online. I enjoy meeting creative people, learning new skills, and turning ideas into things that people actually use', '2026-06-15 07:08:25'),
(3, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-06-14 18:56:35'),
(4, 'Caelix Alejandro Rivera', 'Cale', 'Sculptor, Painter, Part time Model and Theraputic Sound Engineer', 'Bachelor of Arts in Audio Production  University of Texas at Austin', 'Austin, Texas, USA', 'San Antonio, Texas, USA', 'It\'s complicated', '1996-09-14', NULL, 'caelixkole@gmail.com', NULL, 'Conservative', 'Christian', '\"Do not pray for an easy life; pray for the strength to endure a difficult one. Do not pray for tasks equal to your powers; pray for powers equal to your tasks. Then the doing of your work shall be no miracle, but you shall be the miracle. Every day you shall wonder at yourself, at the richness of life which has come in you by the grace of God.\"\r\n— Phillips Brooks', 'Internet Culture,  Music Production,  Memes,  Psychology,  Technology,  Travel,  Photography', 'I speak 4 Languages and im a home body thats pretty much it :)', '2026-06-14 20:23:36');

-- --------------------------------------------------------

--
-- Table structure for table `user_family_members`
--

CREATE TABLE `user_family_members` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `member_name` varchar(100) NOT NULL,
  `relationship` varchar(60) NOT NULL,
  `profile_pic` varchar(255) DEFAULT NULL,
  `consent_given` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_family_members`
--

INSERT INTO `user_family_members` (`id`, `user_id`, `member_name`, `relationship`, `profile_pic`, `consent_given`, `created_at`) VALUES
(1, 4, 'Jose Rivera', 'Father', 'uploads/family/1781467267_6a2f0883b57f6.jpg', 1, '2026-06-14 20:01:07'),
(2, 4, 'Olivia Withers Rivera', 'Mother', 'uploads/family/1781467422_6a2f091eadc54.jpg', 1, '2026-06-14 20:03:42'),
(3, 4, 'Amelia Rivera', 'Sister', 'uploads/family/1781467470_6a2f094e4d3db.jpg', 1, '2026-06-14 20:04:30'),
(4, 1, 'Naya Sylos', 'Sister', 'uploads/family/1781507172_6a2fa46467461.jpg', 1, '2026-06-15 07:06:12'),
(5, 1, 'Jamila River Sylos', 'Mother', 'uploads/family/1781507266_6a2fa4c240056.jpg', 1, '2026-06-15 07:07:46'),
(6, 1, 'Steven Sylos', 'Brother', 'uploads/family/1781507368_6a2fa528a0e7e.jpg', 1, '2026-06-15 07:09:28');

-- --------------------------------------------------------

--
-- Table structure for table `user_interactions`
--

CREATE TABLE `user_interactions` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `meme_id` int(11) NOT NULL,
  `interaction_type` enum('view','like','repost','favorite') NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_interactions`
--

INSERT INTO `user_interactions` (`id`, `user_id`, `meme_id`, `interaction_type`, `created_at`) VALUES
(7, 2, 1, 'favorite', '2026-04-20 04:30:10'),
(8, 2, 1, 'like', '2026-04-20 04:30:12'),
(10, 1, 66, 'like', '2026-04-23 06:26:40'),
(13, 1, 11, 'favorite', '2026-04-23 07:38:56'),
(14, 1, 2, 'like', '2026-04-23 07:40:41'),
(17, 1, 16, 'repost', '2026-04-23 08:30:48'),
(18, 1, 19, 'repost', '2026-04-23 08:30:51'),
(20, 1, 3, 'repost', '2026-04-23 08:36:16'),
(21, 1, 3, 'like', '2026-04-23 08:36:34'),
(29, 2, 2, 'favorite', '2026-04-30 03:58:36'),
(30, 2, 40, 'favorite', '2026-04-30 03:58:47'),
(31, 2, 68, 'favorite', '2026-04-30 08:13:37'),
(32, 2, 67, 'like', '2026-05-05 05:44:41'),
(33, 7, 85, 'favorite', '2026-05-05 14:21:18'),
(34, 7, 3, 'like', '2026-05-05 16:18:37'),
(37, 7, 68, 'repost', '2026-05-09 08:14:36'),
(39, 7, 88, 'like', '2026-05-09 12:50:07'),
(40, 7, 90, 'like', '2026-05-09 12:55:10'),
(41, 7, 89, 'like', '2026-05-09 12:55:11'),
(44, 7, 89, 'repost', '2026-05-09 12:57:19'),
(46, 5, 68, 'repost', '2026-05-12 12:31:40'),
(54, 1, 85, 'view', '2026-05-14 10:54:10'),
(55, 1, 11, 'view', '2026-05-14 10:56:38'),
(56, 3, 95, 'like', '2026-05-14 22:03:42'),
(57, 3, 97, 'repost', '2026-05-14 22:03:54'),
(58, 1, 95, 'like', '2026-05-14 22:04:51'),
(59, 1, 95, 'repost', '2026-05-14 22:05:07'),
(62, 8, 39, 'repost', '2026-05-22 06:40:34'),
(63, 4, 85, 'like', '2026-05-22 07:48:22'),
(64, 4, 16, 'repost', '2026-05-22 07:57:26'),
(65, 4, 85, 'repost', '2026-05-22 08:23:18'),
(67, 3, 7, 'favorite', '2026-05-22 14:01:29'),
(69, 4, 67, 'repost', '2026-05-23 06:06:53'),
(70, 2, 7, 'favorite', '2026-05-23 08:39:20'),
(71, 2, 85, 'favorite', '2026-05-23 11:27:29'),
(75, 7, 16, 'favorite', '2026-05-30 05:27:54'),
(76, 7, 85, 'repost', '2026-05-30 05:28:20'),
(77, 7, 48, 'favorite', '2026-05-30 05:29:37'),
(79, 3, 54, 'repost', '2026-05-30 06:18:06'),
(80, 7, 39, 'favorite', '2026-05-30 07:16:12'),
(81, 7, 66, 'favorite', '2026-05-30 07:17:03'),
(82, 2, 67, 'favorite', '2026-05-30 18:31:09'),
(83, 2, 54, 'favorite', '2026-05-30 18:31:46'),
(84, 2, 19, 'repost', '2026-06-05 07:15:08'),
(85, 3, 3, 'like', '2026-06-13 08:54:51'),
(86, 3, 1, 'like', '2026-06-13 08:55:15'),
(87, 16, 116, 'view', '2026-07-15 10:05:52'),
(88, 16, 66, 'view', '2026-07-15 10:06:42'),
(89, 16, 11, 'view', '2026-07-15 10:06:59'),
(90, 16, 11, 'view', '2026-07-15 10:12:51'),
(91, 3, 116, 'view', '2026-07-15 13:30:06'),
(92, 3, 54, 'view', '2026-07-15 18:34:06'),
(93, 16, 54, 'view', '2026-07-15 18:45:28'),
(94, 16, 54, 'view', '2026-07-15 18:45:42'),
(95, 16, 54, 'view', '2026-07-15 18:45:57'),
(96, 16, 54, 'view', '2026-07-15 18:47:59'),
(97, 16, 54, 'view', '2026-07-15 18:51:26'),
(98, 16, 11, 'view', '2026-07-15 19:12:09'),
(99, 16, 54, 'view', '2026-07-15 19:12:55'),
(100, 16, 116, 'view', '2026-07-15 19:33:28'),
(101, 16, 11, 'view', '2026-07-16 05:51:17'),
(102, 16, 7, 'view', '2026-07-16 06:56:14'),
(103, 7, 38, 'like', '2026-07-18 12:07:55'),
(104, 7, 7, 'favorite', '2026-07-18 13:21:28'),
(105, 7, 6, 'like', '2026-07-18 15:09:16'),
(106, 7, 39, 'like', '2026-07-19 05:54:14'),
(107, 7, 67, 'favorite', '2026-07-19 06:04:35'),
(111, 2, 119, 'like', '2026-07-19 08:44:09'),
(112, 3, 18, 'repost', '2026-07-19 08:55:50'),
(113, 7, 18, 'repost', '2026-07-20 07:13:14'),
(114, 7, 68, 'repost', '2026-07-20 07:22:22'),
(122, 7, 67, 'repost', '2026-07-20 07:55:27'),
(124, 7, 116, 'view', '2026-07-20 08:14:45'),
(127, 7, 124, 'like', '2026-07-22 18:53:29'),
(131, 7, 7, 'like', '2026-07-22 19:39:16'),
(132, 7, 120, 'like', '2026-07-23 08:02:46'),
(133, 7, 124, 'like', '2026-07-24 08:48:30'),
(141, 7, 140, 'like', '2026-07-29 19:34:43'),
(143, 7, 120, 'view', '2026-07-30 07:37:38');

-- --------------------------------------------------------

--
-- Table structure for table `user_life_events`
--

CREATE TABLE `user_life_events` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `event_type` varchar(60) NOT NULL,
  `event_title` varchar(150) NOT NULL,
  `event_date` date DEFAULT NULL,
  `description` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_life_events`
--

INSERT INTO `user_life_events` (`id`, `user_id`, `event_type`, `event_title`, `event_date`, `description`, `created_at`) VALUES
(1, 4, 'Graduation', 'Graduated from UT Austin', '2019-05-23', NULL, '2026-06-14 20:05:30'),
(2, 4, 'Business Launch', 'Started independent Sound Studio', '2021-02-19', NULL, '2026-06-14 20:06:50'),
(3, 4, 'Moved Cities', 'Moved to Austin, Texas', '2023-01-30', NULL, '2026-06-14 20:07:41'),
(4, 4, 'Award / Achievement', 'Featured in Sound Design Monthly', '2025-10-02', 'It was really something i never expected but here i am', '2026-06-14 20:09:08');

-- --------------------------------------------------------

--
-- Table structure for table `user_reports`
--

CREATE TABLE `user_reports` (
  `id` int(10) UNSIGNED NOT NULL,
  `reporter_id` int(10) UNSIGNED NOT NULL,
  `reported_user_id` int(10) UNSIGNED NOT NULL,
  `reported_username` varchar(50) NOT NULL DEFAULT '',
  `reason` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('pending','under_review','resolved','dismissed') NOT NULL DEFAULT 'pending',
  `admin_notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_reports`
--

INSERT INTO `user_reports` (`id`, `reporter_id`, `reported_user_id`, `reported_username`, `reason`, `description`, `status`, `admin_notes`, `created_at`, `updated_at`) VALUES
(1, 3, 4, 'Caelix', 'Suspicious or Bot Activity', NULL, 'pending', NULL, '2026-06-23 08:19:23', '2026-06-23 08:19:23'),
(2, 3, 1, 'Tyven', 'Hate Speech', 'Reporter provided User ID: DZ-10E34AFAD', 'pending', NULL, '2026-06-23 09:24:33', '2026-06-23 09:24:33'),
(3, 3, 2, 'Dani', 'Harassment or Bullying', NULL, 'pending', NULL, '2026-07-15 13:30:43', '2026-07-15 13:30:43');

-- --------------------------------------------------------

--
-- Table structure for table `user_sessions`
--

CREATE TABLE `user_sessions` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `session_id` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_settings`
--

CREATE TABLE `user_settings` (
  `id` int(30) UNSIGNED NOT NULL,
  `user_id` int(30) UNSIGNED NOT NULL,
  `show_profile_pic` tinyint(1) NOT NULL DEFAULT 1,
  `allow_messages` tinyint(1) NOT NULL DEFAULT 1,
  `show_online_status` tinyint(1) NOT NULL DEFAULT 1,
  `allow_anonymous_post` tinyint(1) NOT NULL DEFAULT 1,
  `notify_likes` tinyint(1) NOT NULL DEFAULT 1,
  `notify_follows` tinyint(1) NOT NULL DEFAULT 1,
  `notify_messages` tinyint(1) NOT NULL DEFAULT 1,
  `dark_mode` tinyint(1) NOT NULL DEFAULT 1,
  `autoplay_videos` tinyint(1) NOT NULL DEFAULT 1,
  `reduce_animation` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `followers_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `following_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `Bio` varchar(600) DEFAULT NULL,
  `pronouns` varchar(60) DEFAULT NULL,
  `activity_vibe` varchar(60) NOT NULL DEFAULT 'Flexible',
  `user_type` varchar(20) NOT NULL DEFAULT 'Dezzmer',
  `other_accounts` text DEFAULT NULL,
  `is_private` tinyint(1) NOT NULL DEFAULT 0,
  `is_fully_private` tinyint(1) NOT NULL DEFAULT 0,
  `profile_music` varchar(255) DEFAULT NULL,
  `profile_music_name` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_settings`
--

INSERT INTO `user_settings` (`id`, `user_id`, `show_profile_pic`, `allow_messages`, `show_online_status`, `allow_anonymous_post`, `notify_likes`, `notify_follows`, `notify_messages`, `dark_mode`, `autoplay_videos`, `reduce_animation`, `created_at`, `followers_count`, `following_count`, `Bio`, `pronouns`, `activity_vibe`, `user_type`, `other_accounts`, `is_private`, `is_fully_private`, `profile_music`, `profile_music_name`) VALUES
(1, 3, 1, 1, 0, 1, 1, 1, 1, 1, 1, 0, '2026-03-23 04:09:53', 0, 0, 'Content creator | Storyteller | Free spirit\nPassionate about art, life, and everything in between\nBusiness or personal inquiries  geomarlin8@gmail.com', 'She/Her', 'Early morning (5–9 AM)', 'Dezzmer', '', 0, 1, NULL, NULL),
(2, 2, 1, 1, 1, 1, 1, 1, 1, 1, 1, 0, '2026-03-23 07:54:18', 0, 0, '✨ Living life with curiosity, ambition, and a genuine appreciation for the little things that make every day memorable.\n\n🌍 Passionate about exploring new places, meeting new people, and creating unforgettable memories.\n\n🎵 Music is always playing.\n📸 Capturing moments worth remembering.\n☕ Coffee lover.\n🍜 Always down to try good food.\n✈️ Dreaming of the next adventure.\n💪 Working on becoming stronger—inside and out.\n🎮 Gaming to unwind.\n🌅 A sucker for sunsets, late-night drives, and peaceful moments.\n\n💭 Believer in honesty, loyalty, kindness, and staying true to yourself. Life feels better', 'He/Him', 'Night (9 PM–1 AM)', 'Dezzmer', '@leah,@Armstrong,@starboy', 0, 0, NULL, NULL),
(3, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 0, '2026-03-23 10:11:10', 0, 0, 'Somewhere between I ve got this figured out and what am I doing,depending on the day. I build things I probably don\'t need, break things I definitely shouldn\'t touch, and fix them at 2AM like it\'s a personality trait. If it runs smoothly on the first try, I get suspicious.\n\nI\'m into clean design, messy ideas, late-night coding spirals, and pretending I\'ll sleep early (I won\'t). I collect random inspiration like it\'s currency and forget where I saved it half the time.\n\nCurrently testing: life, projects, and patience.\n\n? tyven.loop.test@maildrip.dev', 'He/Him', 'Early morning (5–9 AM)', 'Dezzmer', '', 0, 0, NULL, NULL),
(4, 5, 1, 1, 1, 1, 1, 1, 1, 1, 1, 0, '2026-03-23 10:11:57', 0, 0, '', 'He/Him', 'Early morning (5–9 AM)', 'Peumer', '@Dani,@Tyven', 0, 0, NULL, NULL),
(5, 4, 1, 1, 1, 1, 1, 1, 1, 1, 1, 0, '2026-03-23 11:54:56', 0, 0, 'Sculptor | Painter | Part-time model | Full-time problem. \nCreating things with my hands since before it was cool.\nArt is the only thing that makes sense to me ,everything else is just noise.\n\nGod-fearing but still out here doing the absolute most ?\nIf you know me, you know. If you don\'t, you\'re missing out.\n\nCurrently obsessed with: late night painting sessions, good music, and people who actually have something interesting to say.\n\nCommissions & collabs ? caelixkole@gmail.com\nDon\'t be shy, I don\'t bite (much) ?\n\n\"Create something today even if it\'s terrible.\"  that\'s the energy.', 'He/Him', 'Late night (1–5 AM)', 'Dezzmer', 'Dani, @Cake-Boss', 0, 0, NULL, NULL),
(7, 7, 1, 1, 1, 1, 1, 1, 1, 1, 1, 0, '2026-04-09 06:17:55', 0, 0, 'Born in the Middle East, building for the world.\nTech enthusiast with a passion for turning ideas into products that actually matter. I live at the intersection of culture and code — where tradition meets innovation.\nAlways learning, always building, never settling.', 'He/Him', 'Afternoon (12–5 PM)', 'Dezzmer', NULL, 1, 0, NULL, NULL),
(8, 8, 1, 1, 1, 1, 1, 1, 1, 1, 1, 0, '2026-04-20 14:02:52', 0, 0, 'Pretty, petty, and everything in between.\nBlack girl magic is not just a saying  it is a whole lifestyle. I laugh loud, love hard, and look good doing both.\nToo blessed to be stressed, too fine to be ignored.', 'She/Her', 'Early morning (5–9 AM)', 'Peumer', '', 0, 0, NULL, NULL),
(11, 13, 1, 1, 1, 1, 1, 1, 1, 1, 1, 0, '2026-05-20 06:46:37', 0, 0, NULL, 'He/Him', 'Afternoon (12–5 PM)', 'Dezzmer', NULL, 0, 0, 'music_13_1780731816.mp3', 'After all this time - Patrick Jordan Patrikios'),
(13, 16, 1, 1, 1, 1, 1, 1, 1, 1, 1, 0, '2026-06-16 06:31:36', 0, 0, 'Just a girl who believes in late night conversations, genuine connections, and the kind of laughter that makes your stomach hurt 🤍\nCreative by nature. Curious by choice. I find beauty in the little things — a good book, a warm cup of coffee, sunsets that hit different, and music that says everything words can\'t 🎶🌅\nI\'m the friend who shows up, the dreamer who never stops, and the overthinker who somehow always figures it out 💫\nLife\'s too short for bad energy, fake smiles, or playing small. So I choose joy. I choose growth. I choose to be exactly who I am — messy, magical, and alwa', 'She | Her', 'Evening (5–9 PM)', 'Dezzmer', NULL, 0, 0, NULL, NULL),
(14, 19, 1, 1, 1, 1, 1, 1, 1, 1, 1, 0, '2026-07-25 06:45:52', 0, 0, 'Curious by nature and always looking for something new to learn. I enjoy meaningful conversations, creative ideas, good music, photography, late-night thoughts, and discovering places that make life feel a little more interesting. Whether I’m exploring a new hobby, working on personal goals, or simply appreciating the little moments, I believe growth comes from staying open to new experiences. I value honesty, loyalty, and people who can make me laugh without trying too hard. Here to connect with genuine people, share memorable moments, and leave every day knowing I learned something new. Life', NULL, '', '', NULL, 0, 0, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `vibe_votes`
--

CREATE TABLE `vibe_votes` (
  `id` int(10) UNSIGNED NOT NULL,
  `voter_id` int(10) UNSIGNED NOT NULL,
  `profile_id` int(10) UNSIGNED NOT NULL,
  `vibe` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `vibe_votes`
--

INSERT INTO `vibe_votes` (`id`, `voter_id`, `profile_id`, `vibe`, `created_at`) VALUES
(1, 3, 8, 'Main character 🔥', '2026-05-10 08:27:21'),
(2, 5, 8, 'Main character 🔥', '2026-05-10 08:48:05'),
(3, 5, 7, 'Underrated legend 👑', '2026-05-10 09:05:24'),
(11, 4, 8, 'Knows they’re attractive 😭', '2026-05-10 09:19:07'),
(12, 1, 8, 'Knows they’re attractive 😭', '2026-05-10 09:19:49'),
(13, 2, 8, 'Knows they’re attractive 😭', '2026-05-10 09:20:14'),
(14, 8, 1, 'Looks like a problem 😭', '2026-05-10 10:26:45'),
(15, 8, 3, 'Quiet luxury 🖤', '2026-05-10 10:34:19'),
(16, 8, 5, 'Chill but dangerous 😈', '2026-05-10 18:56:28'),
(17, 5, 1, 'Underrated legend 👑', '2026-05-19 13:13:13'),
(19, 4, 16, 'Unbothered elite 😌', '2026-06-16 07:06:29'),
(20, 16, 4, 'Chill but dangerous 😈', '2026-06-16 07:08:39');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `audios`
--
ALTER TABLE `audios`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_visibility` (`visibility`),
  ADD KEY `idx_category` (`category`),
  ADD KEY `idx_uploader` (`uploader_id`);

--
-- Indexes for table `audio_saves`
--
ALTER TABLE `audio_saves`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_save` (`user_id`,`audio_id`),
  ADD KEY `idx_audio` (`audio_id`);

--
-- Indexes for table `blocks`
--
ALTER TABLE `blocks`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_block` (`blocker_id`,`blocked_id`),
  ADD KEY `fk_blocked` (`blocked_id`);

--
-- Indexes for table `collections`
--
ALTER TABLE `collections`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_public_name` (`is_public`,`name`);

--
-- Indexes for table `collection_items`
--
ALTER TABLE `collection_items`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_item` (`collection_id`,`meme_id`),
  ADD KEY `idx_col` (`collection_id`);

--
-- Indexes for table `comments`
--
ALTER TABLE `comments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `meme_id` (`meme_id`);

--
-- Indexes for table `content`
--
ALTER TABLE `content`
  ADD PRIMARY KEY (`id`),
  ADD KEY `creator_id` (`creator_id`);

--
-- Indexes for table `content_admin_log`
--
ALTER TABLE `content_admin_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_page` (`page_user_id`),
  ADD KEY `idx_admin` (`acting_admin_id`),
  ADD KEY `idx_content` (`content_type`,`content_id`);

--
-- Indexes for table `content_reports`
--
ALTER TABLE `content_reports`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_reporter` (`reporter_id`),
  ADD KEY `idx_content` (`content_type`,`content_id`),
  ADD KEY `idx_owner` (`content_owner_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_created` (`created_at`);

--
-- Indexes for table `downloads`
--
ALTER TABLE `downloads`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_download` (`user_id`,`meme_id`);

--
-- Indexes for table `favorites`
--
ALTER TABLE `favorites`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_idx` (`user_id`),
  ADD KEY `meme_idx` (`meme_id`);

--
-- Indexes for table `feedback`
--
ALTER TABLE `feedback`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_category` (`category`),
  ADD KEY `idx_pinned` (`is_pinned`),
  ADD KEY `idx_duplicate` (`is_duplicate_of`);

--
-- Indexes for table `feedback_status_log`
--
ALTER TABLE `feedback_status_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_feedback` (`feedback_id`);

--
-- Indexes for table `feedback_votes`
--
ALTER TABLE `feedback_votes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_vote` (`feedback_id`,`user_id`),
  ADD KEY `idx_user` (`user_id`);

--
-- Indexes for table `followers`
--
ALTER TABLE `followers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_follow` (`follower_id`,`following_id`),
  ADD KEY `following_id` (`following_id`);

--
-- Indexes for table `follows`
--
ALTER TABLE `follows`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_follow` (`follower_id`,`following_id`),
  ADD KEY `fk_follows_following` (`following_id`);

--
-- Indexes for table `groups`
--
ALTER TABLE `groups`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by_idx` (`created_by`);

--
-- Indexes for table `group_activity_log`
--
ALTER TABLE `group_activity_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_group` (`group_id`,`created_at`),
  ADD KEY `idx_actor` (`actor_id`),
  ADD KEY `idx_target` (`target_user_id`);

--
-- Indexes for table `group_admins`
--
ALTER TABLE `group_admins`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_admin` (`group_id`,`user_id`);

--
-- Indexes for table `group_bans`
--
ALTER TABLE `group_bans`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_ban` (`group_id`,`banned_id`),
  ADD KEY `idx_banned_id` (`banned_id`);

--
-- Indexes for table `group_events`
--
ALTER TABLE `group_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_group` (`group_id`),
  ADD KEY `idx_date` (`event_date`);

--
-- Indexes for table `group_event_rsvps`
--
ALTER TABLE `group_event_rsvps`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_event_user_type` (`event_id`,`user_id`,`rsvp_type`),
  ADD KEY `idx_event` (`event_id`),
  ADD KEY `idx_user` (`user_id`);

--
-- Indexes for table `group_hashtags`
--
ALTER TABLE `group_hashtags`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_group_tag` (`group_id`,`tag`),
  ADD KEY `idx_group` (`group_id`);

--
-- Indexes for table `group_highlights`
--
ALTER TABLE `group_highlights`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_group` (`group_id`),
  ADD KEY `idx_posted_by` (`posted_by`);

--
-- Indexes for table `group_highlight_likes`
--
ALTER TABLE `group_highlight_likes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_like` (`highlight_id`,`user_id`);

--
-- Indexes for table `group_highlight_poll_options`
--
ALTER TABLE `group_highlight_poll_options`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_highlight` (`highlight_id`);

--
-- Indexes for table `group_highlight_poll_votes`
--
ALTER TABLE `group_highlight_poll_votes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_vote` (`highlight_id`,`user_id`),
  ADD KEY `idx_option` (`option_id`);

--
-- Indexes for table `group_join_requests`
--
ALTER TABLE `group_join_requests`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_request` (`group_id`,`user_id`);

--
-- Indexes for table `group_members`
--
ALTER TABLE `group_members`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_member` (`user_id`,`group_id`),
  ADD KEY `fk_group_members_group` (`group_id`);

--
-- Indexes for table `group_name_history`
--
ALTER TABLE `group_name_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_group` (`group_id`);

--
-- Indexes for table `group_notif_off`
--
ALTER TABLE `group_notif_off`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_notif_off` (`user_id`,`group_id`);

--
-- Indexes for table `group_pins`
--
ALTER TABLE `group_pins`
  ADD PRIMARY KEY (`user_id`,`group_id`);

--
-- Indexes for table `group_posts`
--
ALTER TABLE `group_posts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `group_idx` (`group_id`),
  ADD KEY `posted_by_idx` (`posted_by`),
  ADD KEY `fk_group_posts_meme` (`meme_id`);

--
-- Indexes for table `group_post_reports`
--
ALTER TABLE `group_post_reports`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `group_reactions`
--
ALTER TABLE `group_reactions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_reaction` (`user_id`,`group_post_id`),
  ADD KEY `fk_group_reactions_post` (`group_post_id`);

--
-- Indexes for table `group_search_recents`
--
ALTER TABLE `group_search_recents`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_recent` (`user_id`,`group_id`,`item_type`,`item_id`),
  ADD KEY `idx_lookup` (`user_id`,`group_id`);

--
-- Indexes for table `group_views`
--
ALTER TABLE `group_views`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_viewer` (`viewer_id`),
  ADD KEY `idx_group` (`group_id`);

--
-- Indexes for table `highlights`
--
ALTER TABLE `highlights`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `likes`
--
ALTER TABLE `likes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_like` (`user_id`,`meme_id`),
  ADD KEY `fk_likes_meme` (`meme_id`);

--
-- Indexes for table `linked_accounts`
--
ALTER TABLE `linked_accounts`
  ADD PRIMARY KEY (`primary_user_id`,`linked_user_id`);

--
-- Indexes for table `links`
--
ALTER TABLE `links`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `memes`
--
ALTER TABLE `memes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `uploaded_by_idx` (`uploaded_by`);

--
-- Indexes for table `meme_articles`
--
ALTER TABLE `meme_articles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`);

--
-- Indexes for table `meme_article_tags`
--
ALTER TABLE `meme_article_tags`
  ADD PRIMARY KEY (`article_id`,`tag`);

--
-- Indexes for table `meme_audio`
--
ALTER TABLE `meme_audio`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_meme` (`meme_id`),
  ADD KEY `idx_audio` (`audio_id`);

--
-- Indexes for table `meme_comments`
--
ALTER TABLE `meme_comments`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `meme_edits`
--
ALTER TABLE `meme_edits`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `meme_media`
--
ALTER TABLE `meme_media`
  ADD PRIMARY KEY (`id`),
  ADD KEY `meme_id` (`meme_id`,`position`);

--
-- Indexes for table `messages`
--
ALTER TABLE `messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sender_idx` (`sender_id`),
  ADD KEY `receiver_idx` (`receiver_id`);

--
-- Indexes for table `message_hidden`
--
ALTER TABLE `message_hidden`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_hidden` (`message_id`,`user_id`),
  ADD KEY `user_idx` (`user_id`);

--
-- Indexes for table `message_reactions`
--
ALTER TABLE `message_reactions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_reaction` (`message_id`,`user_id`),
  ADD KEY `message_idx` (`message_id`),
  ADD KEY `fk_msgreact_user` (`user_id`);

--
-- Indexes for table `muted_groups`
--
ALTER TABLE `muted_groups`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_mute` (`user_id`,`group_id`);

--
-- Indexes for table `muted_users`
--
ALTER TABLE `muted_users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_mute` (`muter_id`,`muted_id`),
  ADD KEY `fk_muted_id` (`muted_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `notification_reads`
--
ALTER TABLE `notification_reads`
  ADD PRIMARY KEY (`user_id`);

--
-- Indexes for table `notification_seen`
--
ALTER TABLE `notification_seen`
  ADD PRIMARY KEY (`user_id`,`seen_date`);

--
-- Indexes for table `orbiter_scores`
--
ALTER TABLE `orbiter_scores`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_target_actor` (`target_id`,`actor_id`),
  ADD KEY `idx_target` (`target_id`),
  ADD KEY `idx_actor` (`actor_id`);

--
-- Indexes for table `page_admins`
--
ALTER TABLE `page_admins`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_admin` (`page_user_id`,`admin_user_id`),
  ADD KEY `idx_admin_user` (`admin_user_id`);

--
-- Indexes for table `page_details`
--
ALTER TABLE `page_details`
  ADD PRIMARY KEY (`user_id`),
  ADD KEY `idx_created_by` (`created_by`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `password_reset_tokens`
--
ALTER TABLE `password_reset_tokens`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_token_hash` (`token_hash`),
  ADD KEY `idx_user_id` (`user_id`);

--
-- Indexes for table `pinned_collections`
--
ALTER TABLE `pinned_collections`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_user_collection` (`user_id`,`collection_id`);

--
-- Indexes for table `pinned_posts`
--
ALTER TABLE `pinned_posts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_user_meme` (`user_id`,`meme_id`);

--
-- Indexes for table `pod_discussions`
--
ALTER TABLE `pod_discussions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `group_id` (`group_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `pod_discussion_media`
--
ALTER TABLE `pod_discussion_media`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_discussion` (`discussion_id`);

--
-- Indexes for table `pod_discussion_replies`
--
ALTER TABLE `pod_discussion_replies`
  ADD PRIMARY KEY (`id`),
  ADD KEY `discussion_id` (`discussion_id`),
  ADD KEY `parent_id` (`parent_id`);

--
-- Indexes for table `pod_discussion_reports`
--
ALTER TABLE `pod_discussion_reports`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `pod_likes`
--
ALTER TABLE `pod_likes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_user_disc` (`discussion_id`,`user_id`),
  ADD KEY `discussion_id` (`discussion_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `pod_poll_options`
--
ALTER TABLE `pod_poll_options`
  ADD PRIMARY KEY (`id`),
  ADD KEY `discussion_id` (`discussion_id`);

--
-- Indexes for table `pod_poll_votes`
--
ALTER TABLE `pod_poll_votes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `discussion_id` (`discussion_id`,`user_id`);

--
-- Indexes for table `pod_reactions`
--
ALTER TABLE `pod_reactions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `discussion_id` (`discussion_id`,`user_id`,`emoji`);

--
-- Indexes for table `pod_reports`
--
ALTER TABLE `pod_reports`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_reporter` (`reporter_id`),
  ADD KEY `idx_group` (`group_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_created` (`created_at`);

--
-- Indexes for table `profile_views`
--
ALTER TABLE `profile_views`
  ADD PRIMARY KEY (`id`),
  ADD KEY `viewer_id` (`viewer_id`),
  ADD KEY `profile_id` (`profile_id`);

--
-- Indexes for table `profile_view_hidden`
--
ALTER TABLE `profile_view_hidden`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_hide` (`hider_id`,`target_id`),
  ADD KEY `idx_target` (`target_id`),
  ADD KEY `idx_expires` (`expires_at`);

--
-- Indexes for table `remix`
--
ALTER TABLE `remix`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `report_action_log`
--
ALTER TABLE `report_action_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_report` (`report_type`,`report_id`),
  ADD KEY `idx_admin` (`admin_id`),
  ADD KEY `idx_created` (`created_at`);

--
-- Indexes for table `reposts`
--
ALTER TABLE `reposts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_idx` (`user_id`),
  ADD KEY `meme_idx` (`meme_id`);

--
-- Indexes for table `reset_attempts`
--
ALTER TABLE `reset_attempts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_identifier` (`identifier`),
  ADD KEY `idx_attempted` (`attempted_at`);

--
-- Indexes for table `restricted_users`
--
ALTER TABLE `restricted_users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_restrict` (`restrictor_id`,`restricted_id`),
  ADD KEY `fk_restricted_id` (`restricted_id`);

--
-- Indexes for table `saved_sounds`
--
ALTER TABLE `saved_sounds`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_save` (`user_id`,`sound_id`),
  ADD KEY `sound_id` (`sound_id`);

--
-- Indexes for table `search_queries`
--
ALTER TABLE `search_queries`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_query` (`query_text`),
  ADD KEY `idx_count` (`total_search_count`),
  ADD KEY `idx_last` (`last_seen`);

--
-- Indexes for table `search_recents`
--
ALTER TABLE `search_recents`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_user_recent` (`user_id`,`type`,`ref_id`),
  ADD KEY `idx_user` (`user_id`);

--
-- Indexes for table `search_trending`
--
ALTER TABLE `search_trending`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_trend` (`query_text`,`time_window`),
  ADD KEY `idx_vel` (`velocity_score`);

--
-- Indexes for table `search_user_signals`
--
ALTER TABLE `search_user_signals`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_type` (`signal_type`);

--
-- Indexes for table `sounds`
--
ALTER TABLE `sounds`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `original_user_id` (`original_user_id`);

--
-- Indexes for table `sound_folders`
--
ALTER TABLE `sound_folders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_public_name` (`is_public`,`name`);

--
-- Indexes for table `sound_folder_items`
--
ALTER TABLE `sound_folder_items`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `folder_id` (`folder_id`,`sound_id`),
  ADD KEY `folder_id_2` (`folder_id`);

--
-- Indexes for table `sponsorships`
--
ALTER TABLE `sponsorships`
  ADD PRIMARY KEY (`id`),
  ADD KEY `status` (`status`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `subscriptions`
--
ALTER TABLE `subscriptions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `owner_idx` (`owner_id`),
  ADD KEY `subscriber_idx` (`subscriber_id`);

--
-- Indexes for table `subscription_content`
--
ALTER TABLE `subscription_content`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `owner_unique` (`owner_id`);

--
-- Indexes for table `subscription_memes`
--
ALTER TABLE `subscription_memes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `owner_id` (`owner_id`);

--
-- Indexes for table `subscription_tiers`
--
ALTER TABLE `subscription_tiers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `owner_id` (`owner_id`);

--
-- Indexes for table `track_requests`
--
ALTER TABLE `track_requests`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_request` (`requester_id`,`target_id`),
  ADD KEY `idx_target` (`target_id`),
  ADD KEY `idx_requester` (`requester_id`);

--
-- Indexes for table `unfollow_log`
--
ALTER TABLE `unfollow_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `unfollowed_by` (`unfollowed_by`);

--
-- Indexes for table `username_history`
--
ALTER TABLE `username_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user` (`user_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `unique_dz_id` (`dz_id`),
  ADD KEY `idx_is_page` (`is_page`);

--
-- Indexes for table `user_about`
--
ALTER TABLE `user_about`
  ADD PRIMARY KEY (`user_id`);

--
-- Indexes for table `user_family_members`
--
ALTER TABLE `user_family_members`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_family_user` (`user_id`);

--
-- Indexes for table `user_interactions`
--
ALTER TABLE `user_interactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `meme_id` (`meme_id`);

--
-- Indexes for table `user_life_events`
--
ALTER TABLE `user_life_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_events_user` (`user_id`);

--
-- Indexes for table `user_reports`
--
ALTER TABLE `user_reports`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_reporter` (`reporter_id`),
  ADD KEY `idx_reported_user` (`reported_user_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_reason` (`reason`),
  ADD KEY `idx_created` (`created_at`);

--
-- Indexes for table `user_sessions`
--
ALTER TABLE `user_sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`);

--
-- Indexes for table `user_settings`
--
ALTER TABLE `user_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`);

--
-- Indexes for table `vibe_votes`
--
ALTER TABLE `vibe_votes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_vote` (`voter_id`,`profile_id`),
  ADD KEY `fk_vibe_profile` (`profile_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `audios`
--
ALTER TABLE `audios`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `audio_saves`
--
ALTER TABLE `audio_saves`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `blocks`
--
ALTER TABLE `blocks`
  MODIFY `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=126;

--
-- AUTO_INCREMENT for table `collections`
--
ALTER TABLE `collections`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `collection_items`
--
ALTER TABLE `collection_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=28;

--
-- AUTO_INCREMENT for table `comments`
--
ALTER TABLE `comments`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `content`
--
ALTER TABLE `content`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `content_admin_log`
--
ALTER TABLE `content_admin_log`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `content_reports`
--
ALTER TABLE `content_reports`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `downloads`
--
ALTER TABLE `downloads`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `favorites`
--
ALTER TABLE `favorites`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=204;

--
-- AUTO_INCREMENT for table `feedback`
--
ALTER TABLE `feedback`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `feedback_status_log`
--
ALTER TABLE `feedback_status_log`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `feedback_votes`
--
ALTER TABLE `feedback_votes`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `followers`
--
ALTER TABLE `followers`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=408;

--
-- AUTO_INCREMENT for table `follows`
--
ALTER TABLE `follows`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `groups`
--
ALTER TABLE `groups`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `group_activity_log`
--
ALTER TABLE `group_activity_log`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

--
-- AUTO_INCREMENT for table `group_admins`
--
ALTER TABLE `group_admins`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `group_bans`
--
ALTER TABLE `group_bans`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `group_events`
--
ALTER TABLE `group_events`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `group_event_rsvps`
--
ALTER TABLE `group_event_rsvps`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `group_hashtags`
--
ALTER TABLE `group_hashtags`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `group_highlights`
--
ALTER TABLE `group_highlights`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `group_highlight_likes`
--
ALTER TABLE `group_highlight_likes`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `group_highlight_poll_options`
--
ALTER TABLE `group_highlight_poll_options`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `group_highlight_poll_votes`
--
ALTER TABLE `group_highlight_poll_votes`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `group_join_requests`
--
ALTER TABLE `group_join_requests`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `group_members`
--
ALTER TABLE `group_members`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=165;

--
-- AUTO_INCREMENT for table `group_name_history`
--
ALTER TABLE `group_name_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `group_notif_off`
--
ALTER TABLE `group_notif_off`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `group_posts`
--
ALTER TABLE `group_posts`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=37;

--
-- AUTO_INCREMENT for table `group_post_reports`
--
ALTER TABLE `group_post_reports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `group_reactions`
--
ALTER TABLE `group_reactions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `group_search_recents`
--
ALTER TABLE `group_search_recents`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `group_views`
--
ALTER TABLE `group_views`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=149;

--
-- AUTO_INCREMENT for table `highlights`
--
ALTER TABLE `highlights`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `likes`
--
ALTER TABLE `likes`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=300;

--
-- AUTO_INCREMENT for table `links`
--
ALTER TABLE `links`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `memes`
--
ALTER TABLE `memes`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=148;

--
-- AUTO_INCREMENT for table `meme_articles`
--
ALTER TABLE `meme_articles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `meme_audio`
--
ALTER TABLE `meme_audio`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `meme_comments`
--
ALTER TABLE `meme_comments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `meme_edits`
--
ALTER TABLE `meme_edits`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `meme_media`
--
ALTER TABLE `meme_media`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=144;

--
-- AUTO_INCREMENT for table `messages`
--
ALTER TABLE `messages`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=86;

--
-- AUTO_INCREMENT for table `message_hidden`
--
ALTER TABLE `message_hidden`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `message_reactions`
--
ALTER TABLE `message_reactions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `muted_groups`
--
ALTER TABLE `muted_groups`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `muted_users`
--
ALTER TABLE `muted_users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `orbiter_scores`
--
ALTER TABLE `orbiter_scores`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `page_admins`
--
ALTER TABLE `page_admins`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `password_reset_tokens`
--
ALTER TABLE `password_reset_tokens`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `pinned_collections`
--
ALTER TABLE `pinned_collections`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `pinned_posts`
--
ALTER TABLE `pinned_posts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `pod_discussions`
--
ALTER TABLE `pod_discussions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `pod_discussion_media`
--
ALTER TABLE `pod_discussion_media`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `pod_discussion_replies`
--
ALTER TABLE `pod_discussion_replies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `pod_discussion_reports`
--
ALTER TABLE `pod_discussion_reports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pod_likes`
--
ALTER TABLE `pod_likes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `pod_poll_options`
--
ALTER TABLE `pod_poll_options`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `pod_poll_votes`
--
ALTER TABLE `pod_poll_votes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `pod_reactions`
--
ALTER TABLE `pod_reactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `pod_reports`
--
ALTER TABLE `pod_reports`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `profile_views`
--
ALTER TABLE `profile_views`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1501;

--
-- AUTO_INCREMENT for table `profile_view_hidden`
--
ALTER TABLE `profile_view_hidden`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `remix`
--
ALTER TABLE `remix`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- AUTO_INCREMENT for table `report_action_log`
--
ALTER TABLE `report_action_log`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `reposts`
--
ALTER TABLE `reposts`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=226;

--
-- AUTO_INCREMENT for table `reset_attempts`
--
ALTER TABLE `reset_attempts`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `restricted_users`
--
ALTER TABLE `restricted_users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `saved_sounds`
--
ALTER TABLE `saved_sounds`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `search_queries`
--
ALTER TABLE `search_queries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=96;

--
-- AUTO_INCREMENT for table `search_recents`
--
ALTER TABLE `search_recents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=194;

--
-- AUTO_INCREMENT for table `search_trending`
--
ALTER TABLE `search_trending`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=98;

--
-- AUTO_INCREMENT for table `search_user_signals`
--
ALTER TABLE `search_user_signals`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=96;

--
-- AUTO_INCREMENT for table `sounds`
--
ALTER TABLE `sounds`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `sound_folders`
--
ALTER TABLE `sound_folders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `sound_folder_items`
--
ALTER TABLE `sound_folder_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `sponsorships`
--
ALTER TABLE `sponsorships`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `subscriptions`
--
ALTER TABLE `subscriptions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `subscription_content`
--
ALTER TABLE `subscription_content`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `subscription_memes`
--
ALTER TABLE `subscription_memes`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `subscription_tiers`
--
ALTER TABLE `subscription_tiers`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `track_requests`
--
ALTER TABLE `track_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=33;

--
-- AUTO_INCREMENT for table `unfollow_log`
--
ALTER TABLE `unfollow_log`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=96;

--
-- AUTO_INCREMENT for table `username_history`
--
ALTER TABLE `username_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `user_family_members`
--
ALTER TABLE `user_family_members`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `user_interactions`
--
ALTER TABLE `user_interactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=144;

--
-- AUTO_INCREMENT for table `user_life_events`
--
ALTER TABLE `user_life_events`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `user_reports`
--
ALTER TABLE `user_reports`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `user_sessions`
--
ALTER TABLE `user_sessions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_settings`
--
ALTER TABLE `user_settings`
  MODIFY `id` int(30) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `vibe_votes`
--
ALTER TABLE `vibe_votes`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `audios`
--
ALTER TABLE `audios`
  ADD CONSTRAINT `fk_audios_uploader` FOREIGN KEY (`uploader_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `audio_saves`
--
ALTER TABLE `audio_saves`
  ADD CONSTRAINT `fk_audiosaves_audio` FOREIGN KEY (`audio_id`) REFERENCES `audios` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_audiosaves_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `blocks`
--
ALTER TABLE `blocks`
  ADD CONSTRAINT `fk_blocked` FOREIGN KEY (`blocked_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_blocker` FOREIGN KEY (`blocker_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `comments`
--
ALTER TABLE `comments`
  ADD CONSTRAINT `comments_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `comments_ibfk_2` FOREIGN KEY (`meme_id`) REFERENCES `memes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `content`
--
ALTER TABLE `content`
  ADD CONSTRAINT `content_ibfk_1` FOREIGN KEY (`creator_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `content_admin_log`
--
ALTER TABLE `content_admin_log`
  ADD CONSTRAINT `fk_cal_admin` FOREIGN KEY (`acting_admin_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_cal_page` FOREIGN KEY (`page_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `favorites`
--
ALTER TABLE `favorites`
  ADD CONSTRAINT `fk_favorites_meme` FOREIGN KEY (`meme_id`) REFERENCES `memes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_favorites_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `feedback`
--
ALTER TABLE `feedback`
  ADD CONSTRAINT `fk_feedback_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `feedback_status_log`
--
ALTER TABLE `feedback_status_log`
  ADD CONSTRAINT `fk_fblog_feedback` FOREIGN KEY (`feedback_id`) REFERENCES `feedback` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `feedback_votes`
--
ALTER TABLE `feedback_votes`
  ADD CONSTRAINT `fk_fbvote_feedback` FOREIGN KEY (`feedback_id`) REFERENCES `feedback` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_fbvote_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `followers`
--
ALTER TABLE `followers`
  ADD CONSTRAINT `followers_ibfk_1` FOREIGN KEY (`follower_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `followers_ibfk_2` FOREIGN KEY (`following_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `follows`
--
ALTER TABLE `follows`
  ADD CONSTRAINT `fk_follows_follower` FOREIGN KEY (`follower_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_follows_following` FOREIGN KEY (`following_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `groups`
--
ALTER TABLE `groups`
  ADD CONSTRAINT `fk_groups_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `group_activity_log`
--
ALTER TABLE `group_activity_log`
  ADD CONSTRAINT `fk_gal_actor` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_gal_group` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_gal_target` FOREIGN KEY (`target_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `group_members`
--
ALTER TABLE `group_members`
  ADD CONSTRAINT `fk_group_members_group` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_group_members_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `group_posts`
--
ALTER TABLE `group_posts`
  ADD CONSTRAINT `fk_group_posts_group` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_group_posts_meme` FOREIGN KEY (`meme_id`) REFERENCES `memes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_group_posts_user` FOREIGN KEY (`posted_by`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `group_reactions`
--
ALTER TABLE `group_reactions`
  ADD CONSTRAINT `fk_group_reactions_post` FOREIGN KEY (`group_post_id`) REFERENCES `group_posts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_group_reactions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `likes`
--
ALTER TABLE `likes`
  ADD CONSTRAINT `fk_likes_meme` FOREIGN KEY (`meme_id`) REFERENCES `memes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_likes_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `links`
--
ALTER TABLE `links`
  ADD CONSTRAINT `links_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `memes`
--
ALTER TABLE `memes`
  ADD CONSTRAINT `fk_memes_uploaded_by` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `meme_audio`
--
ALTER TABLE `meme_audio`
  ADD CONSTRAINT `fk_meme_audio_audio` FOREIGN KEY (`audio_id`) REFERENCES `audios` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_meme_audio_meme` FOREIGN KEY (`meme_id`) REFERENCES `memes` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `meme_media`
--
ALTER TABLE `meme_media`
  ADD CONSTRAINT `meme_media_ibfk_1` FOREIGN KEY (`meme_id`) REFERENCES `memes` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `messages`
--
ALTER TABLE `messages`
  ADD CONSTRAINT `fk_messages_receiver` FOREIGN KEY (`receiver_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_messages_sender` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `message_hidden`
--
ALTER TABLE `message_hidden`
  ADD CONSTRAINT `fk_hidden_message` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_hidden_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `message_reactions`
--
ALTER TABLE `message_reactions`
  ADD CONSTRAINT `fk_msgreact_message` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_msgreact_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `muted_users`
--
ALTER TABLE `muted_users`
  ADD CONSTRAINT `fk_muted` FOREIGN KEY (`muted_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_muter` FOREIGN KEY (`muter_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `page_admins`
--
ALTER TABLE `page_admins`
  ADD CONSTRAINT `fk_pa_admin` FOREIGN KEY (`admin_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_pa_page` FOREIGN KEY (`page_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `page_details`
--
ALTER TABLE `page_details`
  ADD CONSTRAINT `fk_pd_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_pd_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `pod_discussion_media`
--
ALTER TABLE `pod_discussion_media`
  ADD CONSTRAINT `pod_discussion_media_ibfk_1` FOREIGN KEY (`discussion_id`) REFERENCES `pod_discussions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `profile_views`
--
ALTER TABLE `profile_views`
  ADD CONSTRAINT `profile_views_ibfk_1` FOREIGN KEY (`viewer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `profile_views_ibfk_2` FOREIGN KEY (`profile_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `profile_view_hidden`
--
ALTER TABLE `profile_view_hidden`
  ADD CONSTRAINT `fk_pvh_hider` FOREIGN KEY (`hider_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_pvh_target` FOREIGN KEY (`target_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `reposts`
--
ALTER TABLE `reposts`
  ADD CONSTRAINT `fk_reposts_meme` FOREIGN KEY (`meme_id`) REFERENCES `memes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_reposts_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `restricted_users`
--
ALTER TABLE `restricted_users`
  ADD CONSTRAINT `fk_restricted` FOREIGN KEY (`restricted_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_restrictor` FOREIGN KEY (`restrictor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `saved_sounds`
--
ALTER TABLE `saved_sounds`
  ADD CONSTRAINT `saved_sounds_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `saved_sounds_ibfk_2` FOREIGN KEY (`sound_id`) REFERENCES `sounds` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sounds`
--
ALTER TABLE `sounds`
  ADD CONSTRAINT `sounds_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `sounds_ibfk_2` FOREIGN KEY (`original_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `subscriptions`
--
ALTER TABLE `subscriptions`
  ADD CONSTRAINT `subscriptions_ibfk_1` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `subscriptions_ibfk_2` FOREIGN KEY (`subscriber_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `subscription_content`
--
ALTER TABLE `subscription_content`
  ADD CONSTRAINT `subscription_content_ibfk_1` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `subscription_memes`
--
ALTER TABLE `subscription_memes`
  ADD CONSTRAINT `subscription_memes_ibfk_1` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `subscription_tiers`
--
ALTER TABLE `subscription_tiers`
  ADD CONSTRAINT `subscription_tiers_ibfk_1` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_about`
--
ALTER TABLE `user_about`
  ADD CONSTRAINT `fk_user_about_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_settings`
--
ALTER TABLE `user_settings`
  ADD CONSTRAINT `user_settings_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `vibe_votes`
--
ALTER TABLE `vibe_votes`
  ADD CONSTRAINT `fk_vibe_profile` FOREIGN KEY (`profile_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_vibe_voter` FOREIGN KEY (`voter_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

DELIMITER $$
--
-- Events
--
CREATE DEFINER=`root`@`localhost` EVENT `purge_expired_view_hidden` ON SCHEDULE EVERY 1 DAY STARTS '2026-06-27 11:22:18' ON COMPLETION NOT PRESERVE ENABLE DO DELETE FROM `profile_view_hidden` WHERE `expires_at` < NOW()$$

DELIMITER ;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
