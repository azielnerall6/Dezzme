<?php
session_start();
include 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

/* ─────────────────────────────────────────────────────────────
   RENDER HELPERS
───────────────────────────────────────────────────────────── */
function hp_head($icon, $title, $desc) {
    echo '<div class="section-eyebrow"><div class="section-icon-wrap"><i class="' . $icon . '"></i></div>'
       . '<div><div class="section-heading">' . $title . '</div><div class="section-desc">' . $desc . '</div></div></div>';
}
function hp_rows($rows) {
    echo '<div class="info-list">';
    foreach ($rows as $r) {
        echo '<div class="info-row"><div class="info-icon"><i class="' . $r[0] . '"></i></div>'
           . '<div class="info-content"><div class="info-title">' . $r[1] . '</div><div class="info-text">' . $r[2] . '</div></div></div>';
    }
    echo '</div>';
}
function hp_acc($items, $open_first = false) {
    echo '<div class="accordion">';
    foreach ($items as $i => $r) {
        echo '<div class="acc-item' . (($open_first && $i === 0) ? ' open' : '') . '">'
           . '<button type="button" class="acc-trigger" onclick="toggleAcc(this)">'
           . '<span class="acc-icon"><i class="' . $r[0] . '"></i></span>'
           . '<span class="acc-trigger-text">' . $r[1] . '</span>'
           . '<i class="ri-arrow-down-s-line acc-chevron"></i></button>'
           . '<div class="acc-body"><div class="acc-in"><div class="acc-txt">' . $r[2] . '</div></div></div>'
           . '</div>';
    }
    echo '</div>';
}

/* ═════════════════════════════════════════════════════════════
   CONTENT — every tab is an array of [icon, title, text]
═════════════════════════════════════════════════════════════ */

$T = [];

/* ── WHAT IS DEZZME ── */
$T['what'] = ['ri-information-line', 'What is Dezzme', 'The platform in plain words', [
    ['ri-folder-3-line', 'Dezzme at a glance', "Dezzme is a meme storage and sharing platform. It started as a personal gallery for keeping memes without using phone storage, and grew into a full social platform built around meme culture.<br><br>On Dezzme you can post image, video and text memes, attach sounds, publish long-form videos, join Pods (communities), build collections, write Memepedia entries, run a business Page, message people, and vote on the vibe of a profile."],
    ['ri-smartphone-line', 'The problem it solves', "Memes you delete from your phone are gone for good. Phone storage fills up. Finding one specific meme in a camera roll is slow. There is no proper library for memes.<br><br>Dezzme keeps your memes in the cloud and makes them searchable by caption and hashtag, so any meme you saved is a few taps away."],
    ['ri-cloud-line', 'Zero phone storage used', "Everything you upload is stored on Dezzme, not on your device. Your phone's storage stays untouched however much you post. If you want a copy on your phone you can download it, but you never have to."],
    ['ri-user-smile-line', 'Who Dezzme is for', "Dezzmers who want to keep and organise memes, creators who want an audience, communities who want a place to share a theme, and brands who want to reach people through Pages and promoted posts."],
    ['ri-compass-3-line', 'What you can do here', "<ul><li>Post image, video and text memes, with several files in one post</li><li>Attach sounds and reuse sounds from other Dezzmers</li><li>Track Dezzmers, repost, favourite and download their memes</li><li>Build public or private collections</li><li>Message Dezzmers you track</li><li>Join or create Pods with discussions, polls, events and files</li><li>Publish long-form videos with comments</li><li>Create Highlights on your profile</li><li>Create a business Page and promote posts as ads</li><li>Read and write Memepedia entries</li><li>Give and receive anonymous Vibe Check votes</li></ul>"],
    ['ri-sparkling-line', 'What makes Dezzme different', "<strong>No public comments on regular memes.</strong> Replies happen in messages, so there are no pile-ons under a post.<br><br><strong>Privacy by relationship.</strong> What you can do with someone's content depends on whether you track them.<br><br><strong>Anonymous vibes.</strong> Nobody can see who voted what.<br><br><strong>Pods with real moderation tools.</strong> Owners and admins can approve, remove and ban.<br><br><strong>Transparency.</strong> Every account has an About This Account page showing when it joined and how often its username changed."],
    ['ri-scales-3-line', 'The rules in short', "<ul><li>Be kind. No harassment, hate or threats.</li><li>No spam or repeated posting of the same meme.</li><li>Label ads, partnerships and AI-made content honestly.</li><li>Flag mature content as adult.</li><li>Post only what you made or have the right to share.</li><li>Do not share other people's private information.</li><li>Do not try to scam or impersonate anyone.</li></ul>Breaking the rules can lead to removed posts, removal from Pods, or action against your account."],
]];

/* ── GETTING STARTED ── */
$T['start'] = ['ri-rocket-line', 'Getting Started', 'Account, login, password and your first day', [
    ['ri-user-add-line', 'Creating an account', "<ol><li>Open the sign-up page.</li><li>Choose a username. It must be unique.</li><li>Enter your email address. It must be unique too, so one email cannot be used on two accounts.</li><li>Choose a password.</li><li>Submit. Dezzme sends a verification email.</li></ol>Pick a username you are happy to keep. You can change it later, but your old usernames stay in your account history."],
    ['ri-fingerprint-line', 'Your Dezzme ID (DZ-ID)', "Every account gets a permanent ID like <strong>DZ-XXXXXXXXX</strong>. It never changes, even if your username does.<br><br>Use it to find someone exactly, to prove an account is yours, or to report a Dezzmer accurately when two accounts have similar names. Sharing your DZ-ID is safe. Never share your password or a reset link."],
    ['ri-mail-check-line', 'Verifying your email', "Open the email from Dezzme and tap the verification link. Verification proves the address is yours and is needed for password recovery.<br><br>If nothing arrives: check spam, wait a few minutes, confirm you typed the address correctly, and request a new email."],
    ['ri-login-box-line', 'Logging in and out', "Log in with your account details. If you are not logged in, any page sends you to the login page.<br><br>You can be logged in on more than one device. Log out on shared devices every time. If you think someone else has access, change your password."],
    ['ri-lock-password-line', 'Forgot your password', "<ol><li>Choose Forgot password on the login page.</li><li>Enter your email address.</li><li>Open the email and tap the reset link.</li><li>Choose a new password.</li></ol>The link works once and expires after a short time. Too many reset attempts in a short period are blocked for safety, so wait a while before trying again. Never share the link."],
    ['ri-image-edit-line', 'Setting up your profile', "A complete profile looks more genuine and gets tracked more often.<ul><li><strong>Profile picture</strong> with the red glowing border</li><li><strong>Nickname</strong> shown next to your username</li><li><strong>Bio</strong> up to 600 characters</li><li><strong>Pronouns</strong> shown as a badge</li><li><strong>Activity Hours</strong> so people know when you are usually online</li><li><strong>Dezzmer Type</strong> describing how you use Dezzme</li><li><strong>Links</strong> to other places you are</li></ul>"],
    ['ri-checkbox-circle-line', 'Your first-day checklist', "<ol><li>Upload your first meme.</li><li>Track a few Dezzmers whose memes you like.</li><li>Join a Pod that matches your humour.</li><li>Favourite some memes to start your private vault.</li><li>Open Settings and review who can message you.</li><li>Check your notification switches.</li><li>Read the Scam and Privacy Protection Centre once.</li></ol>"],
    ['ri-shield-user-line', 'Age and adult content', "Some features ask you to confirm your age, for example verification and adult Pods. Adult content is hidden unless you opt in. If you are under 18, do not confirm adult status and tell a trusted adult if anything makes you uncomfortable."],
]];

/* ── APP GUIDE ── */
$T['pages'] = ['ri-layout-grid-line', 'App Guide', 'Every page and what it does', [
    ['ri-home-4-line', 'Home Feed', "The main feed. The default sort is <strong>Trending</strong>, personalised to your hashtag habits, with fair visibility for new Dezzmers and fresh uploads. Image, video and text memes are mixed together. Text memes sit on a dark card so you can spot them instantly.<br><br>At the top is a row of Dezzmers you track. Below it are the hashtag pills and the three-dot filter menu. The feed reshuffles on every refresh.<br><br><strong>Hashtag pills:</strong> All, #Funny, #Me, #Text, #Memes, #Gaming, #Love, #School, #Anime, #Music, #Movies, #Sports, #Football.<br><br><strong>Three-dot filters:</strong> Most Liked, Most Reposted, Most Favourited, Most Viewed, Trending, Trackers, Tracking."],
    ['ri-user-3-line', 'Profile page', "Your picture with the glowing border and online dot, username, nickname, Trackers, Tracking and Pods counts, pronouns, Activity Hours, Dezzmer Type, bio, links, Highlights, Vibe Check and your meme grid.<br><br>Tap the profile picture for Share, Copy Link and QR Code.<br><br><strong>On your own profile:</strong> Edit Picture, Untracked, Insights.<br><strong>On someone else's:</strong> Track, Message, Block, Report and the vibe options."],
    ['ri-search-2-line', 'Search and Discover', "One search box for Dezzmers, Pods, memes, hashtags, Memepedia entries, collections and sounds. Under the box you get your Recent searches and Trending searches. Results from every type appear together."],
    ['ri-upload-cloud-line', 'Upload', "Tabs for Image, Video and Text. Drag and drop or choose files, write a caption, attach a sound, then open the advanced options: Partnership, AI-generated label, Ad, Connected post and Adult content. Finish with Post Content."],
    ['ri-file-text-line', 'Text Feed', "A feed only for text memes, with the same hashtag pills and three-dot filters as Home. Every post shows how long ago it was posted."],
    ['ri-team-line', 'Community', "Where you find Pods. You can search Dezzmers and Pods, see suggested Pods, see recent uploads and create a Pod. Dismiss a suggested Pod and it will not be suggested again."],
    ['ri-group-line', 'Pod pages', "Each Pod has a picture, banner, title, description, category, guidelines, hashtags, member list, posts, discussions, events, files and highlights. Members post. Owners and admins moderate. See the Pods tab for everything."],
    ['ri-music-2-line', 'Sounds and Audio', "Your uploaded sounds, saved audios and sound folders. Preview, organise, download and share audio without using phone storage."],
    ['ri-movie-2-line', 'Long-form videos', "Longer videos with titles, thumbnails, descriptions, hashtags, comments, likes, dislikes, saves, shares and downloads. Your watch progress is remembered."],
    ['ri-user-follow-line', 'Tracks page', "Trackers, Tracking and Mutual (called Circle on your own profile) in one tabbed page. Swipe between tabs, search each list, and track, untrack or request to track straight from a row."],
    ['ri-bookmark-3-line', 'Favourites', "Your private vault. Only you can see what is in it."],
    ['ri-folder-music-line', 'Collections', "Folders of memes you create. Each is public or private. Pin the ones you use most."],
    ['ri-repeat-2-line', 'Reposts page', "The memes a Dezzmer reposted. Visible only to their Trackers."],
    ['ri-chat-3-line', 'Messages', "Direct messages, shared memes, attachments and reactions. You can only message Dezzmers you track."],
    ['ri-notification-3-line', 'Activity (notifications)', "Opened with the bell. Shows everything that happened today. See the Notifications tab for full detail."],
    ['ri-book-2-line', 'Memepedia', "The meme encyclopedia. Entries explain where a meme came from, how it spread and what it means."],
    ['ri-pages-line', 'Business Pages', "Pages for brands, businesses and creators, separate from your personal profile."],
    ['ri-verified-badge-line', 'Verification', "Request a verified badge and manage your plan."],
    ['ri-history-line', 'History', "Your own activity trail, including profiles you visited."],
    ['ri-shield-user-line', 'About This Account', "A transparency page for every account: date joined, account location, the number of username changes, memes posted, Trackers and Tracking counts and, on someone else's page, the mutual Trackers you share."],
    ['ri-feedback-line', 'Feedback', "Report bugs, suggest features and vote on other Dezzmers' ideas. See the Feedback tab."],
    ['ri-settings-3-line', 'Settings', "Privacy, notifications, display, linked accounts, and your blocked, muted and restricted lists."],
]];

/* ── POSTING ── */
$T['posting'] = ['ri-upload-cloud-line', 'Posting Memes', 'Uploads, captions, labels and deleting', [
    ['ri-image-line', 'Uploading an image meme', "<ol><li>Open Upload and choose the Image tab.</li><li>Drag a file in or tap to choose one or more.</li><li>Write a caption with hashtags.</li><li>Optionally attach a sound and set advanced options.</li><li>Tap Post Content.</li></ol>JPG, PNG, WEBP and GIF work. Several images become a swipeable carousel in the order you picked them."],
    ['ri-video-line', 'Uploading a video meme', "Choose the Video tab and pick an MP4. Videos autoplay in feeds if Autoplay Videos is on in Settings. You can mix videos, images and GIFs in one post."],
    ['ri-file-text-line', 'Posting a text meme', "Choose the Text tab and type your meme. It appears on a dark card in Home and in the Text Feed."],
    ['ri-stack-line', 'Multi-media posts (carousels)', "One post can hold many images, GIFs and videos. Viewers swipe through them. If you upload many files at once, keep a steady connection until the post appears."],
    ['ri-hashtag', 'Captions, hashtags and mentions', "Type <strong>#</strong> before a word to make a hashtag, for example #funny. Hashtags make your meme searchable and feed the trending system.<br><br>Type <strong>@</strong> followed by a username to mention a Dezzmer. They get a notification for mentions in posts, highlights and Pod highlight comments."],
    ['ri-music-line', 'Adding a sound', "Attach one of your sounds or a public audio. Two sliders balance the sound: <strong>Music volume</strong> for the attached audio and <strong>Video audio volume</strong> for the video's own sound.<br><br>When you upload a video, its audio is saved as an <em>Original audio</em> that other Dezzmers can reuse."],
    ['ri-repeat-line', 'Remixing someone else\'s meme', "Remix posts your own version of another Dezzmer's meme. A small credit links back to the original and the creator is notified."],
    ['ri-links-line', 'Connected posts (Part 2, Part 3)', "Link a new post to one of your earlier posts and give the link a label such as <em>Part 2</em>. Viewers can jump between parts."],
    ['ri-handshake-line', 'Partnership label', "If a brand gave you something or paid you to post, turn on <strong>Partnership</strong> and type the brand name. The post shows a partnership tag. This is different from an ad: a partnership tells viewers about a collaboration, an ad promotes your post with a button."],
    ['ri-robot-line', 'AI-generated label', "Turn this on if AI made the meme. The post carries an AI label. Do not label real photos as AI or AI images as real."],
    ['ri-advertisement-line', 'Making your post an ad', "Turn on <strong>Ad</strong> in the advanced options to promote the post with a button. Full details, prices and review steps are in the Ads tab."],
    ['ri-eye-close-line', 'Adult content flag', "Mark a post as adult if it has mature themes. Adult posts are hidden from Dezzmers who have not opted in and cannot go into non-adult Pods. Sexual content involving minors, explicit exploitation and graphic violence are never allowed, flag or not."],
    ['ri-user-unfollow-line', 'Anonymous posting', "If Allow Anonymous Posts is on in Settings, the option is available when you post. Anonymous posts still follow every rule and moderators can still act on them."],
    ['ri-group-line', 'Posting inside a Pod', "Post from the Pod page, or share an existing meme to a Pod. It shows in the Pod and under your name elsewhere. Follow the Pod's guidelines. Adult memes cannot go into non-adult Pods."],
    ['ri-pages-line', 'Posting as a Page', "If you are an admin of a business Page you can post in the Page's name. Page posts support captions, sounds, partnership and AI labels, connected posts and ads."],
    ['ri-pushpin-line', 'Pinning a post', "Pin your best meme to keep it first on your profile grid."],
    ['ri-delete-bin-line', 'Deleting a post', "Open the post menu and choose Delete. The meme disappears from feeds, Pods, collections, favourites, reposts and shared links. This cannot be undone."],
    ['ri-error-warning-line', 'Upload failed or stuck', "<ul><li>Check your connection.</li><li>Make sure the file type is supported and not unusually large.</li><li>Try one file at a time.</li><li>Refresh and check your profile in case the post went through.</li></ul>If it keeps failing, send a Bug report through Feedback with the file type and what you saw."],
]];

/* ── INTERACTING ── */
$T['interact'] = ['ri-heart-3-line', 'Interacting with Memes', 'Likes, favourites, reposts, shares and collections', [
    ['ri-heart-3-line', 'Likes', "Tap the heart to like. Likes help a meme trend and tell the creator it landed. Tap again to undo. The creator gets a notification, grouped with other likes from the day."],
    ['ri-bookmark-3-line', 'Favourites', "Private. Only you see them. They are your personal meme vault, and the creator gets a quiet notification that someone saved their meme without learning who your private list contains. You must track a Dezzmer to favourite their memes."],
    ['ri-repeat-2-line', 'Reposts', "Reposting puts a meme on your profile for your Trackers to see. Your reposts show your taste. You must track the creator to repost."],
    ['ri-share-forward-line', 'Sharing', "Share by <strong>Message</strong> to Dezzmers you can message (pick several at once), by <strong>Copy Link</strong>, or through your device's share menu. Shares are counted."],
    ['ri-download-2-line', 'Downloading', "Download a meme to your device. You must track the creator. Repeated downloads by the same Dezzmer count once."],
    ['ri-eye-line', 'Views', "A view counts when a meme is shown to you. Views feed the trending algorithm and show creators their reach."],
    ['ri-folder-add-line', 'Collections', "Create a collection such as <em>Cat memes</em>, name it, choose Public or Private, and add memes. A meme can only appear once in the same collection. Public collections can be found by other Dezzmers and the creator is notified when their meme is added to a public collection."],
    ['ri-pushpin-2-line', 'Pinning collections', "Pin a collection to keep it at the top of your list."],
    ['ri-chat-off-line', 'Where comments exist', "Regular memes have no public comment section. Conversation happens in messages.<br><br>Comments do exist on long-form videos, Pod discussions, Pod highlights, Pod events and Memepedia entries."],
    ['ri-flag-line', 'Reporting a meme', "Open the meme menu, choose Report, pick a reason, add details if you want. Reports are private. The creator is not told who reported."],
    ['ri-eye-off-line', 'Hiding what you do not want', "Mute a Dezzmer to hide their posts quietly. Block to stop all contact. Use feed filters to narrow what you see."],
]];

/* ── AUDIO ── */
$T['audio'] = ['ri-music-2-line', 'Sounds and Audio', 'Upload, save, organise and reuse sounds', [
    ['ri-music-2-line', 'Uploading a sound', "Upload an MP3 with a title and an optional cover picture. It appears on your Sounds page and can be attached to memes."],
    ['ri-record-circle-line', 'Original audio', "Uploading a video saves its audio as an <em>Original audio</em>. Other Dezzmers can reuse it and you stay credited."],
    ['ri-save-3-line', 'Saving sounds and audios', "Tap Save on any sound or audio. Saved items appear in your saved list and can be reused when posting. The creator gets a notification when their sound is saved."],
    ['ri-folder-music-line', 'Sound folders', "Make folders such as <em>Chill Vibes</em> to organise sounds and audios. Folders can be public or private and can have a cover image."],
    ['ri-copyright-line', 'Copyright status', "Each audio is marked <strong>Original</strong>, <strong>Licensed</strong> or <strong>Unknown</strong>. Upload only audio you made or have permission to use. Rights holders can ask for audio to be removed."],
    ['ri-lock-unlock-line', 'Public or private audio', "Public audios can be found and used by anyone. Private audios are only yours."],
    ['ri-headphone-line', 'Profile and highlight music', "Set a song on your profile that plays for visitors. On a Highlight, use one song for the whole Highlight or a different song on each slide, and mute a video's own sound if you prefer the song."],
]];

/* ── LONG-FORM ── */
$T['longform'] = ['ri-movie-2-line', 'Long-form Videos', 'Longer videos with their own comments', [
    ['ri-upload-2-line', 'Publishing a video', "Choose a video file, add a title (up to 100 characters), a description and a thumbnail. Dezzme reads the length and file size. The video appears on your profile and in the long-form section."],
    ['ri-hashtag', 'Hashtags', "Hashtags in the description make your video searchable and are added to the hashtag list."],
    ['ri-thumb-up-line', 'Likes and dislikes', "Viewers can like or dislike. You can hide the like, dislike, save and share counts from public view."],
    ['ri-chat-1-line', 'Comments and replies', "Viewers can comment, reply, like or dislike comments, and mention Dezzmers with @. You can pin a comment on your own video and you can switch comments off for a video. You are notified of comments, replies and likes on your comments."],
    ['ri-play-circle-line', 'Resume where you stopped', "Dezzme remembers how far you watched and marks a video completed when you finish."],
    ['ri-download-line', 'Saves, shares and downloads', "Save to find a video later, share by link or message, or download. Each is counted and the creator is notified."],
    ['ri-bar-chart-line', 'Video milestones', "You get a notification when a video reaches 1,000, 5,000, 10,000, 50,000, 100,000, 500,000 or 1,000,000 views."],
    ['ri-eye-off-line', 'Published, Hidden and Removed', "<strong>Published</strong> is live. <strong>Hidden</strong> is out of view. <strong>Removed</strong> means it broke the rules."],
    ['ri-flag-line', 'Reporting a video', "Use Report on the video and choose a reason. You can report a video once."],
]];

/* ── TRACKING ── */
$T['tracking'] = ['ri-user-follow-line', 'Tracking and Relationships', 'Track, request, mute, restrict, block and link', [
    ['ri-user-follow-line', 'Trackers and Tracking', "<strong>Trackers</strong> are Dezzmers who track you. <strong>Tracking</strong> is who you track. Tracking is Dezzme's version of following, but it also unlocks things: messaging, reposting, downloading, favouriting and vibe voting."],
    ['ri-lock-line', 'Private accounts and requests', "If an account is private you send a <strong>Track Request</strong> and wait. The owner approves or denies. You cannot see restricted content until approved. <strong>Fully private</strong> accounts hide even more of the profile."],
    ['ri-notification-badge-line', 'Approving and denying', "Requests appear in your Activity and on your Tracks page. Approve to let a Dezzmer in. Deny to refuse. They are not told when you deny, and when you approve they get a notification."],
    ['ri-user-unfollow-line', 'Untracking', "Untrack from their profile or the Tracks page. You immediately lose their reposts page, messaging, downloads, favouriting and vibe voting. If the account is private you need approval again. Untracks are logged for account safety."],
    ['ri-team-line', 'Mutuals and Circle', "A Mutual is someone you track who also tracks you. On your own Tracks page the tab is called <strong>Circle</strong>."],
    ['ri-time-line', 'Untracked', "Your own profile has an Untracked view that shows who stopped tracking you, so changes in your numbers are not a mystery."],
    ['ri-volume-mute-line', 'Muting', "Mute hides a Dezzmer's posts and notifications from you without telling them and without ending the tracking relationship."],
    ['ri-eye-line', 'Restricting', "Restrict quietly limits how a Dezzmer can interact with you without a full block. They are not told."],
    ['ri-spam-2-line', 'Blocking', "A blocked Dezzmer's content disappears from every feed and filter. They cannot track, message, repost or interact with you, and you disappear from their activity. You can still find them in search, where they show as blocked. Unblock from Settings or their profile."],
    ['ri-git-merge-line', 'Linked accounts', "If you own two accounts, link them in Settings to switch quickly. Both accounts must be linked to each other."],
]];

/* ── MESSAGES ── */
$T['messages'] = ['ri-chat-3-line', 'Messages', 'Direct messages, attachments and reactions', [
    ['ri-chat-3-line', 'Who you can message', "You must track a Dezzmer before you can message them. You cannot message someone who blocked you or turned messages off."],
    ['ri-attachment-2', 'Attachments and shared memes', "Attach an image or file. When you share a meme into a chat, the other Dezzmer sees a preview they can open."],
    ['ri-emotion-line', 'Reactions', "Press and hold a message to react with an emoji. Change or remove your reaction any time. The sender gets a notification."],
    ['ri-eye-line', 'Seen', "A message shows as seen once the other Dezzmer opens it."],
    ['ri-delete-back-2-line', 'Deleting', "Delete a message for yourself so only you stop seeing it, or unsend it so it shows as <em>[deleted]</em> for both of you."],
    ['ri-flag-2-line', 'Reporting a message', "Report threats, harassment, scams or private information. Moderators can see the reported message."],
    ['ri-volume-up-line', 'Message notifications', "Turn message notifications on or off in Settings. Turning them off also hides message reactions and highlight messages in Activity."],
    ['ri-prohibited-line', 'Pod bans and messaging', "A Dezzmer banned from a Pod cannot message the Pod owner to get around the ban."],
]];

/* ── PROFILE ── */
$T['profile'] = ['ri-user-3-line', 'Your Profile', 'Everything you can show and control', [
    ['ri-id-card-line', 'Profile fields explained', "<strong>Username</strong> is unique and used for mentions. <strong>Nickname</strong> is a display name. <strong>Pronouns</strong> show as a badge. <strong>Activity Hours</strong> say when you are usually online: early morning, afternoon, evening, night, late night or flexible. <strong>Dezzmer Type</strong> describes how you use Dezzme. <strong>Profile type</strong> describes your kind of creator, for example video blogger."],
    ['ri-edit-line', 'Changing your username', "You can change it. Dezzme saves your old usernames in your account history, and Dezzmers who track you are told when you change it so nobody loses you."],
    ['ri-article-line', 'Bio and other accounts', "Your bio supports text and mentions. List your other accounts so Dezzmers know which are really yours."],
    ['ri-links-line', 'Profile links', "Add links with a title and an icon. Only add sites you control and trust."],
    ['ri-qr-code-line', 'Share, Copy Link and QR code', "Tap your profile picture to share your profile, copy its link or show a QR code for others to scan."],
    ['ri-image-2-line', 'Cover and banner', "Add a cover photo and a banner for a more personal look."],
    ['ri-star-smile-line', 'Highlights', "Highlights are sets of image, video and text slides that stay on your profile.<ul><li>Title up to 30 characters and an optional cover</li><li>One song for the whole Highlight or one per slide</li><li>Mute a video's own sound</li><li>Captions that can mention Dezzmers</li><li>Viewers can like a Highlight and send you a short message from it</li></ul>"],
    ['ri-sparkling-line', 'Vibe Check', "Other Dezzmers vote on the vibe your profile gives. See the Vibe Check tab."],
    ['ri-bar-chart-line', 'Profile views and Insights', "Dezzme counts profile visits. Your Insights show who visited this week, and you get at most one notification per visitor per day. You can also hide your own visit from a specific Dezzmer for a limited time."],
    ['ri-shield-user-line', 'About This Account', "A public page on every account: date joined, account location, number of username changes, memes posted, Trackers and Tracking counts and, on someone else's page, the mutual Trackers you share. It helps you judge whether an account is genuine."],
    ['ri-profile-line', 'About section details', "Optional fields: full name, nickname, occupation, education, current location, hometown, relationship status, date of birth, website, contact email and phone, political and religious views, favourite quotes, interests and a description. If you add your date of birth, Dezzmers who track you get a birthday notification. Share only what you are comfortable with."],
    ['ri-parent-line', 'Family members', "List family members with name, relationship and a photo. Add someone only if they agreed. You must confirm consent."],
    ['ri-calendar-event-line', 'Life events', "Add milestones such as graduations, moves, business launches or awards with a title, date and short note."],
    ['ri-pin-distance-line', 'Pinned posts and collections', "Pin a meme or a collection to keep it first on your profile."],
    ['ri-eye-off-line', 'Online status', "Turn off Show Online Status in Settings and others cannot see when you are active. You will not see theirs either."],
]];

/* ── PODS ── */
$T['pods'] = ['ri-group-line', 'Pods', 'Communities, roles, events, files and polls', [
    ['ri-add-circle-line', 'Creating a Pod', "From Community choose Create Pod. Set a title (up to 100 characters), description, picture, banner and category, and choose Public or Private. You become the owner."],
    ['ri-door-open-line', 'Joining', "Public Pods let you in immediately. Private Pods send a request to the owner and admins, and you are notified when it is approved or declined. Adult Pods need you to confirm you are an adult."],
    ['ri-door-closed-line', 'Leaving', "Tap Leave on the Pod page. Your earlier posts stay unless you delete them."],
    ['ri-lock-line', 'Public and Private Pods', "Public Pods can be seen and joined by anyone. Private Pods hide their content from non-members."],
    ['ri-article-line', 'Posting in a Pod', "Members can post memes. Pod posts also appear under your name elsewhere. Admins can remove posts that break the guidelines."],
    ['ri-chat-poll-line', 'Discussions', "Start a discussion with a title, body, a tag (Discussion, Question, Meme Talk or Poll) and images, GIFs or videos. Replies can be nested. The author can switch comments off. Discussions can be liked, and members can react with emoji."],
    ['ri-bar-chart-horizontal-line', 'Polls and quizzes', "A discussion can be a <strong>vote</strong> poll or a <strong>quiz</strong> with one correct answer. Each Dezzmer gets one vote and results update live. Polls can have an expiry time."],
    ['ri-calendar-event-line', 'Events', "Admins create events with a name, image, date, time and a physical or virtual location (a link). Events are public or private. Members RSVP <strong>Interested</strong> or <strong>Going</strong>, can comment and like comments. If you RSVP, you get a reminder on the day of the event."],
    ['ri-file-text-line', 'Files', "Pods can hold shared files such as PDFs and documents. The owner chooses who can upload: everyone, admins or only the owner. Each file has a title, description, size and download count. Members can save or report a file and admins can pin one."],
    ['ri-star-smile-line', 'Pod Highlights', "Admins create highlights of photos, videos, text and polls. Members can like, comment, reply and vote."],
    ['ri-hashtag', 'Hashtags and categories', "Owners add hashtags and pick a category such as Memes and Humor, Technology, Pets or Fandom, so the right Dezzmers can find the Pod."],
    ['ri-list-check', 'Guidelines', "Owners write custom guidelines. Read them before posting."],
    ['ri-shield-star-line', 'Owner, admins and members', "<strong>Owner:</strong> everything, including renaming, appointing admins, changing who uploads files, pausing and deleting.<br><strong>Admins:</strong> appointed by the owner; approve requests, create events and highlights, remove content.<br><strong>Members:</strong> post and take part."],
    ['ri-user-forbid-line', 'Removing and banning', "Owners and admins can remove a member. A banned Dezzmer leaves immediately, can never rejoin and cannot contact the owner."],
    ['ri-pause-circle-line', 'Pausing', "Owners can pause a Pod to stop activity for a while, then resume it."],
    ['ri-history-line', 'Activity log and name history', "Important actions such as renames, category changes, approvals and pauses are logged. Old names are kept so people can still find a renamed Pod."],
    ['ri-pushpin-line', 'Pin, mute and notifications', "Pin a Pod to keep it first. Mute a Pod to hide it from your feed. Turn off Pod notifications to stay a member without alerts."],
    ['ri-flag-line', 'Reporting', "Report a Pod, a Pod post, a discussion or a file. Choose a reason and add details."],
]];

/* ── BUSINESS PAGES ── */
$T['bizpages'] = ['ri-pages-line', 'Business Pages', 'Brand, business and creator Pages', [
    ['ri-pages-line', 'What a Page is', "A Page is a public presence for a business, brand, creator or project. It is separate from your personal profile, so your private life stays private."],
    ['ri-add-box-line', 'Creating a Page', "Choose a name (up to 60 characters), a category such as Business or Entertainment, a short bio (up to 300 characters), an optional website, a picture and a banner. You must agree to the Page policy."],
    ['ri-team-line', 'Page admins', "The creator is the owner. The owner can add admins to help run the Page. Admins are notified when they are added."],
    ['ri-image-add-line', 'Posting from a Page', "Page admins post memes in the Page's name. Page posts support captions, sounds, partnership and AI labels, connected posts and ads."],
    ['ri-megaphone-line', 'Page ads', "A Page post can be an ad with a button, link, colour and duration, just like a personal post. See the Ads tab."],
    ['ri-user-heart-line', 'Following a Page', "Tap Follow to see a Page's posts. The owner is notified. A Page can also follow Dezzmers."],
    ['ri-volume-mute-line', 'Muting a Page', "Mute a Page to hide its posts without unfollowing."],
    ['ri-links-line', 'Page links', "Add links to your website or shop."],
    ['ri-thumb-up-line', 'Reactions and shares', "Dezzmers can react to and share Page posts. The owner gets a notification."],
]];

/* ── MEMEPEDIA ── */
$T['memepedia'] = ['ri-book-2-line', 'Memepedia', 'The meme encyclopedia', [
    ['ri-book-2-line', 'What it is', "Memepedia explains memes. Each entry covers a meme's origin, creator, spread, variations, platforms, cultural importance and how it changed over time."],
    ['ri-bar-chart-box-line', 'Status labels', "<strong>Trending</strong>, <strong>Rising</strong>, <strong>Classic</strong>, <strong>Dead</strong>, <strong>Revived</strong> and <strong>Legendary</strong>."],
    ['ri-article-line', 'Reading an entry', "A quick summary, a media gallery, tags, origin year and platform, related memes and sections such as Origin, How It Became Popular, Variations, Platforms, Cultural Significance, Evolution and Current Status."],
    ['ri-edit-2-line', 'Editing', "Eligible Dezzmers can suggest edits. Each edit is saved with a summary and the editor's name, and old versions are kept so a bad edit can be reversed. The article's creator is notified of edits."],
    ['ri-chat-1-line', 'Commenting', "Add a comment to an entry, for example to correct a date. Be accurate and respectful. The creator is notified."],
    ['ri-search-line', 'Finding entries', "Search by title, tag or category from the Search page."],
]];

/* ── ADS (NEW SYSTEM: AD POSTS) ── */
$T['ads'] = ['ri-megaphone-line', 'Ads', 'Turn any post into an ad with a button', [
    ['ri-megaphone-line', 'How ads work on Dezzme', "On Dezzme an ad is a normal post that you turn into an ad. It looks like your meme, carries an <strong>Ad</strong> badge, and has a button viewers can tap to go to your link. There is no separate ad account to set up. You make the post, switch on Ad, set the button and the length of the run, and send it for review."],
    ['ri-list-ordered', 'Creating an ad step by step', "<ol><li>Open Upload and add your image, video or text.</li><li>Write your caption.</li><li>Open the advanced options and switch on <strong>Ad</strong>.</li><li>Type your <strong>button text</strong>, up to 50 characters, for example Shop Now, Learn More or Download Now.</li><li>Paste your <strong>button link</strong>, the page the button opens.</li><li>Pick a <strong>button colour</strong>.</li><li>Pick how many <strong>days</strong> the ad should run.</li><li>Check the price shown, then tap Post Content.</li></ol>"],
    ['ri-price-tag-3-line', 'Prices', "Your price depends on how many days you choose, and it is shown before you submit. For example, a 1-day ad costs &#36;5 and a 30-day ad costs &#36;80. Longer runs cost less per day."],
    ['ri-time-line', 'Review and statuses', "Every ad is reviewed before it goes live.<ul><li><strong>Pending:</strong> waiting for review.</li><li><strong>Approved:</strong> live and shown to Dezzmers.</li><li><strong>Rejected:</strong> not shown.</li></ul>You get a notification in Activity when your ad is approved or rejected."],
    ['ri-close-circle-line', 'Why an ad can be rejected', "<ul><li>The link is broken, misleading or goes somewhere different from what the button says.</li><li>It promotes scams, fake giveaways, fake verification or anything illegal.</li><li>It uses someone else's content or brand without permission.</li><li>It contains adult content in a non-adult setting.</li><li>The caption or picture is deceptive.</li></ul>Fix the problem and submit a new ad."],
    ['ri-eye-line', 'How Dezzmers see your ad', "Ads appear in feeds with an <strong>Ad</strong> badge and your button. Dezzmers can like, repost, favourite, share and report an ad like any post, and nobody can comment on it. Ads are always labelled. An unlabelled post that is selling to you should be reported."],
    ['ri-pages-line', 'Ads from a Page', "A business Page can run ads too. When posting from a Page, switch on Ad and set the button text (up to 40 characters), link, colour and days. The same review applies."],
    ['ri-handshake-line', 'Ad versus Partnership', "<strong>Ad:</strong> you pay to promote your own post and add a button.<br><strong>Partnership:</strong> a brand worked with you, and the post is tagged with the brand name. You can use both on one post."],
    ['ri-robot-line', 'Ads made with AI', "If an ad uses AI-made images or video, also switch on the AI-generated label."],
    ['ri-flag-line', 'Reporting a bad ad', "Open the post menu, choose Report, and pick a reason such as Spam or Clickbait, Misinformation or Fake News. Moderators review it. You can also block the advertiser."],
    ['ri-shield-check-line', 'Staying safe around ads', "An ad button can lead anywhere. Check the web address before you enter any details, never send money to someone who messaged you from an ad, and never enter your Dezzme password anywhere except Dezzme. See the Scam and Privacy Protection Centre."],
]];

/* ── NOTIFICATIONS ── */
$T['notifs'] = ['ri-notification-3-line', 'Notifications', 'The bell, Activity, and every kind of alert', [
    ['ri-notification-3-line', 'How the bell works', "The bell sits in the top bar. A red badge shows how many new things happened since you last opened it, and shows 99+ above 99. Tap the bell to open <strong>Activity</strong>, a full-screen list of what happened today. The title shows a small live dot and the total number of events."],
    ['ri-calendar-line', 'Activity resets every day', "Activity shows <strong>today only</strong> and starts fresh at midnight. It holds up to 150 events. The footer tells you how many events there were today."],
    ['ri-mail-unread-line', 'What counts as unread', "A notification is new if it happened after the last time you opened the bell. Opening the bell marks everything as seen and the badge clears. If the same Dezzmer does something again later, for example views your profile a second time, it counts as new and moves to show their latest visit. When everything is seen, a strip says <em>All caught up today</em>."],
    ['ri-refresh-line', 'Live updates', "Activity refreshes by itself about every 10 seconds, instantly when you return to the tab, when your connection comes back and when you open the bell. If something new arrives while the bell is closed you see a small toast saying how many. The dot is red when updates are live and grey when paused or offline. The refresh button in the footer forces an update."],
    ['ri-notification-badge-line', 'Browser notifications', "Dezzme asks permission to show browser notifications a few seconds after you arrive. If you allow it you get a pop-up for new activity even when Dezzme is in another tab. If you blocked it, change that in your browser's site settings."],
    ['ri-layout-grid-line', 'Tabs and search', "Activity has five tabs: <strong>All</strong>, <strong>Social</strong>, <strong>Pods</strong>, <strong>Content</strong> and <strong>Messages</strong>, each with a count. Inside a tab, events are grouped under headings such as Likes or Profile Views, each with its own count. The search box filters by any word, such as a username or Pod name."],
    ['ri-group-line', 'Grouped notifications', "When several Dezzmers do the same thing, Activity combines them: <em>A and B liked your post</em> or <em>A and 3 others liked your post</em>, with stacked pictures. Tap a group to see the full list of Dezzmers and tap any name to open their profile. Grouping applies to likes, reposts, saves, downloads, profile views, new Trackers, video likes, video saves, highlight likes and shares. The same Dezzmer is only counted once per day for each type."],
    ['ri-cursor-line', 'What tapping does', "Tap a row to open the Dezzmer's profile. Rows about a Pod open that Pod, and the Pod name is a blue link. Group rows open the list of Dezzmers. Pod invites open the inviter's profile and the Pod name links to the Pod."],
    ['ri-image-line', 'Posts and engagement', "You are notified when a Dezzmer:<ul><li>likes, reposts, saves or downloads your memes</li><li>comments on a post that allows comments</li><li>remixes your meme</li><li>adds your meme to a public collection</li><li>shares your post</li><li>mentions you in a post caption, a Highlight caption or a Pod highlight comment</li></ul>"],
    ['ri-user-line', 'People and profile', "<ul><li>A new Tracker</li><li>A Track Request, and when yours is approved</li><li>Someone viewed your profile (once per Dezzmer per day, and not if they hid their visit)</li><li>A Vibe Check vote, if Vibe Check is on</li><li>A new post from a Dezzmer you track</li><li>A message, and a reaction to your message</li><li>A birthday today for a Dezzmer you track</li><li>A Dezzmer you track changed their username, shown with their original name</li><li>A Pod invite</li></ul>"],
    ['ri-movie-2-line', 'Highlights, videos, sounds', "<ul><li>Likes and messages on your Highlights</li><li>Likes, saves, shares and downloads on your long-form videos</li><li>Comments on your videos and replies to your comments</li><li>Likes on your comments on videos, events and Pod highlights</li><li>Saves of your sounds and audios</li></ul>"],
    ['ri-group-line', 'Pods', "<ul><li>Someone joined your Pod</li><li>A request to join, if you are an owner or admin</li><li>Your request was approved or declined</li><li>You were appointed admin</li><li>New posts, discussions and files in your Pods</li><li>Likes, replies and nested replies on your discussions</li><li>Votes on your polls and reactions to your discussions</li><li>Likes, comments and replies on your Pod highlights</li></ul>"],
    ['ri-calendar-event-line', 'Pod events', "You are notified when a Dezzmer RSVPs to your event (as going or interested), when someone comments on it, and on the day of an event you RSVPed to you get a reminder with the time."],
    ['ri-megaphone-line', 'Ads, Pages, feedback and Memepedia', "<ul><li>Your ad was approved or rejected</li><li>Someone requested a subscription to you</li><li>Someone followed your Page, reacted to or shared a Page post</li><li>You were made an admin of a Page</li><li>Your Feedback changed status, such as Under review or Completed</li><li>Someone commented on or edited your Memepedia article</li></ul>"],
    ['ri-trophy-line', 'Milestones', "You get a notification the day you reach a milestone.<ul><li><strong>Trackers:</strong> 100, 250, 500, 1,000, 2,500, 5,000, 10,000, 25,000, 50,000 and 100,000</li><li><strong>Profile views:</strong> 1,000, 5,000, 10,000, 50,000, 100,000, 500,000 and 1,000,000</li><li><strong>Video views:</strong> the same milestones as profile views</li></ul>"],
    ['ri-eye-off-line', 'What you will not see', "<ul><li>Anything from a Dezzmer you blocked, or who blocked you</li><li>Anything from a Dezzmer you muted</li><li>Anything from a Pod you turned notifications off for</li><li>Your own actions</li><li>Vibe notifications when your Vibe Check is switched off</li><li>Types you switched off in Settings</li></ul>"],
    ['ri-toggle-line', 'Notification switches', "Settings has three switches:<ul><li><strong>Likes</strong> turns off likes on posts, Pods, videos, Highlights and comments.</li><li><strong>Follows</strong> turns off new Trackers, Track Requests, approvals and Page followers.</li><li><strong>Messages</strong> turns off messages, Highlight messages and message reactions.</li></ul>To silence one Pod without leaving it, use the Pod's notification option."],
    ['ri-tools-line', 'Notification problems', "<ul><li><strong>The badge will not clear:</strong> open the bell once and wait a second.</li><li><strong>Times look off:</strong> they come from Dezzme's own clock and tick forward live.</li><li><strong>No pop-ups:</strong> allow browser notifications for Dezzme.</li><li><strong>Something is missing:</strong> check whether you blocked or muted the Dezzmer, muted the Pod, or switched the type off.</li><li><strong>Yesterday's activity is gone:</strong> Activity only shows today.</li></ul>"],
]];

/* ── SUBSCRIPTIONS & VERIFICATION ── */
$T['money'] = ['ri-vip-crown-line', 'Subscriptions and Verification', 'Paid creator content and verified badges', [
    ['ri-vip-crown-line', 'Creator subscriptions', "A creator can offer exclusive memes in paid tiers such as Standard and Premium, each with its own price, and describe what subscribers get."],
    ['ri-bank-card-line', 'Subscribing', "<ol><li>Open the creator's subscription page and choose a tier.</li><li>Enter your name, email and phone.</li><li>Upload your payment receipt.</li><li>Wait for the creator to review it.</li></ol>Approved subscribers can see the exclusive memes."],
    ['ri-time-line', 'Subscription statuses', "<strong>Pending:</strong> waiting for the creator. <strong>Approved:</strong> you have access. <strong>Declined:</strong> the payment was not accepted."],
    ['ri-error-warning-line', 'Payment safety', "Pay only through the steps shown on Dezzme. Do not send money, codes or receipts to anyone who messages you privately. If you paid and got no access, contact support through Feedback."],
    ['ri-verified-badge-line', 'Verification badge', "Request a verified badge. Plans are <strong>Standard</strong>, <strong>Plus</strong> and <strong>Elite</strong>. You choose a payment method and confirm your age. There is a trial period, and the plan continues afterwards unless you cancel."],
    ['ri-checkbox-circle-line', 'Verification statuses', "A request is Pending, Approved, Rejected or Cancelled. A badge confirms identity. It does not mean Dezzme endorses the account."],
    ['ri-alarm-warning-line', 'Fake verification offers', "Dezzme never asks for your password to verify you. Anyone who offers a badge through a message or a link is a scammer."],
]];

/* ── SEARCH ── */
$T['search'] = ['ri-search-2-line', 'Search and Discovery', 'Finding Dezzmers, Pods, memes and more', [
    ['ri-search-2-line', 'What you can search', "Dezzmers by username, nickname or DZ-ID, Pods, memes by caption, hashtags, Memepedia entries, collections and sounds."],
    ['ri-hashtag', 'Hashtag search', "Start with # to search a hashtag, for example #funny."],
    ['ri-history-line', 'Recent searches', "Your latest searches and opened results are kept so you can jump back. Remove one or clear them all."],
    ['ri-fire-line', 'Trending searches', "Shows what many Dezzmers are searching right now."],
    ['ri-equalizer-line', 'Filters', "Use the hashtag pills and the three-dot menu: Most Liked, Most Reposted, Most Favourited, Most Viewed, Trending, Trackers and Tracking."],
    ['ri-blur-off-line', 'Why something is missing', "Blocked Dezzmers, hidden or removed content and private content you cannot access do not appear. Try shorter words and check spelling."],
    ['ri-lightbulb-flash-line', 'How your feed learns', "Your searches, likes, saves and watch time quietly shape what Trending shows you."],
]];

/* ── SETTINGS ── */
$T['settings'] = ['ri-settings-3-line', 'Settings', 'Control how Dezzme works for you', [
    ['ri-lock-2-line', 'Private and fully private', "<strong>Private:</strong> Dezzmers must request to track you. <strong>Fully private:</strong> hides more of your profile from Dezzmers you have not approved."],
    ['ri-eye-line', 'Online status and profile picture', "Control whether your online dot and profile picture are visible."],
    ['ri-mail-line', 'Allow messages', "Turn off to stop messages from new people."],
    ['ri-user-unfollow-line', 'Anonymous posts', "Controls whether anonymous posting is available to you."],
    ['ri-notification-3-line', 'Notification switches', "Likes, Follows and Messages. See the Notifications tab for exactly what each hides."],
    ['ri-contrast-2-line', 'Dark and light mode', "Dezzme comes in dark mode and light mode. Switch between them in Settings and your choice is remembered on this device and applied to every page, including this one."],
    ['ri-play-circle-line', 'Autoplay videos', "Turn off to stop videos playing as you scroll. It also saves mobile data."],
    ['ri-speed-line', 'Reduce animation', "Turn on to lessen motion if animations bother you or slow your device."],
    ['ri-sparkling-line', 'Vibe Check switch', "Turn Vibe Check on or off for your profile. The choice stays after refresh. When off, you will not get vibe notifications."],
    ['ri-git-merge-line', 'Linked accounts', "Link or unlink your second account."],
    ['ri-prohibited-line', 'Blocked, muted and restricted lists', "See and undo every block, mute and restriction in one place."],
    ['ri-smartphone-line', 'Sessions and security', "Each login is a session. If you think someone else has your account, reset your password and log out everywhere."],
]];

/* ── FEEDBACK ── */
$T['feedback'] = ['ri-feedback-line', 'Feedback', 'Bugs, ideas and how they are tracked', [
    ['ri-send-plane-line', 'Sending feedback', "Open Feedback, choose a category, give it a title (up to 150 characters), write the details and optionally attach a screenshot. You can tick a box to allow follow-up questions."],
    ['ri-list-check', 'Categories', "<strong>Bug</strong> for something broken, <strong>Feature</strong> for an idea, <strong>General</strong>, <strong>Abuse</strong> for rule-breaking you want to flag, and <strong>Other</strong>."],
    ['ri-bug-line', 'Writing a good bug report', "<ul><li>Say what you were doing.</li><li>Say what you expected and what happened.</li><li>Include the device, browser and the page.</li><li>Add a screenshot.</li></ul>"],
    ['ri-thumb-up-line', 'Voting on ideas', "You can vote once on any idea. Ideas with the most votes are looked at first."],
    ['ri-route-line', 'Statuses', "<strong>Submitted</strong>, <strong>Under review</strong>, <strong>Planned</strong>, <strong>In development</strong>, <strong>Completed</strong> or <strong>Declined</strong>. When the status changes you get a notification."],
    ['ri-file-copy-line', 'Duplicates', "If your idea matches an existing one, it is linked to the original so votes are combined."],
]];

/* ── SAFETY (short) ── */
$T['safety'] = ['ri-shield-check-line', 'Safety and Reporting', 'Short guide. The full guide is in the Protection Centre', [
    ['ri-flag-line', 'How reporting works', "You can report Dezzmers, memes, messages, Pods, Pod posts, discussions, files, long-form videos and ads. Choose a reason and add details. A report goes <strong>Pending</strong>, then <strong>Under review</strong>, then <strong>Resolved</strong> or <strong>Dismissed</strong>. Reports are private."],
    ['ri-list-check-2', 'Reasons you can choose', "Misinformation, spam or clickbait, harassment or bullying, hate speech, self-harm or suicide content, disturbing content, sharing private information, suspicious or bot activity, off-topic in a Pod, and other."],
    ['ri-heart-pulse-line', 'If someone is in danger', "If a post or message suggests someone may hurt themselves or others, report it and contact local emergency services. Dezzme is not an emergency service."],
    ['ri-alarm-warning-line', 'Common scams', "Fake giveaways, fake verification, fake brand deals that ask you to pay first, fake receipts, gift-card requests, phishing links that look like Dezzme, fake support accounts and impersonators."],
    ['ri-lock-password-line', 'Protect your account', "Use a strong unique password, never share a reset link, check who emails you and keep your recovery email current."],
    ['ri-eye-off-line', 'Protect your privacy', "Do not post your address, school, phone number or ID. Review what your About section shows. Use private account, block and restrict when needed."],
]];

/* ── VIBE ── */
$vibe_info = [
    ['ri-ballot-line', 'Community voted', "Other Dezzmers vote on the vibe your profile gives. One active vote per Dezzmer. They can change it anytime. The voter list is anonymous."],
    ['ri-user-line', 'On your own profile', "You see <em>Your Vibe Is:</em> with the current top result. You can switch Vibe Check off or on."],
    ['ri-group-line', 'On other profiles', "You see the options and the top vibe. Your previous vote stays highlighted. You must track someone to vote."],
    ['ri-lock-2-line', 'Anonymous', "Nobody knows who voted what. That prevents harassment and revenge voting."],
    ['ri-notification-3-line', 'Vibe notifications', "You are notified when someone votes, and the notification shows the vibe. If a voter is anonymous it says Someone. Turn off Vibe Check to stop these."],
];
$vibes = [
    ['🔥','Main character'], ['🌙','Mysterious'], ['😭','Looks like a problem'], ['⚡','Chaos energy'],
    ['😈','Chill but dangerous'], ['😌','Unbothered'], ['😭',"Knows they're attractive"], ['👑','Underrated legend'],
    ['🔥','Hot ass mess'], ['📶','Unreachable energy'], ['🌸','Soft life era'], ['🚫📱',"Don't text them first"],
    ['🖤','Quiet luxury'], ['🚩','Probably toxic'], ['🏋️','Gym bro / gym menace'], ['😭','Will knock out your teeth'],
    ['📚','Bookworm energy'], ['😏',"Thinks they're all that"], ['💀','Would argue and win'], ['🎭','Always in drama somehow'],
    ['😭',"Acts innocent but isn't"], ['📈','Walking ego boost'],
];

$privacy = [
    ['Meme grid on profile',  ['✓ Yes','yes'],          ['—','no'],               ['—','no']],
    ['Reposts page',          ['—','no'],               ['✓ Trackers','cond'],    ['—','no']],
    ['Downloading memes',     ['—','no'],               ['✓ Trackers','cond'],    ['—','no']],
    ['Favouriting memes',     ['—','no'],               ['✓ Trackers','cond'],    ['—','no']],
    ['Favourites list',       ['—','no'],               ['—','no'],               ['✓ Only You','yes']],
    ['Direct messages',       ['—','no'],               ['—','no'],               ['✓ You + Recipient','yes']],
    ['Sending a message',     ['—','no'],               ['✓ Trackers','cond'],    ['—','no']],
    ['Vibe voting',           ['—','no'],               ['✓ Trackers','cond'],    ['—','no']],
    ['Public Pod content',    ['✓ Yes','yes'],          ['—','no'],               ['—','no']],
    ['Private Pod content',   ['—','no'],               ['✓ Pod Members','cond'], ['—','no']],
    ['Posting in a Pod',      ['—','no'],               ['✓ Pod Members','cond'], ['—','no']],
    ['Private collections',   ['—','no'],               ['—','no'],               ['✓ Only You','yes']],
    ['Public collections',    ['✓ Yes','yes'],          ['—','no'],               ['—','no']],
    ['Profile page',          ['✓ Yes','yes'],          ['—','no'],               ['—','no']],
    ['Profile vibe',          ['✓ Yes','yes'],          ['—','no'],               ['—','no']],
    ['About This Account',    ['✓ Yes','yes'],          ['—','no'],               ['—','no']],
    ['Who voted on a vibe',   ['—','no'],               ['—','no'],               ['✓ Nobody (anonymous)','yes']],
    ['Who reported a post',   ['—','no'],               ['—','no'],               ['✓ Moderators only','yes']],
    ['Private account memes', ['—','no'],               ['✓ Approved Trackers','cond'], ['—','no']],
];

$vocab = [
    ['Dezzmer', 'A Dezzme member, anyone on the platform'],
    ['DZ-ID', 'Your permanent account ID that starts with DZ-'],
    ['Trackers', 'Dezzmers who track you (followers)'],
    ['Tracking', 'Dezzmers you track (following)'],
    ['Track Request', 'A request to track a private account'],
    ['Mutual', 'A Dezzmer you track who also tracks you'],
    ['Circle', 'Your Mutual tab, as shown on your own profile'],
    ['Untracked', 'Dezzmers who stopped tracking you'],
    ['Pod', 'A community group built around a meme theme'],
    ['Pod Member', 'A Dezzmer who joined a Pod'],
    ['Activity', 'Your notifications for today, opened from the bell'],
    ['Activity Hours', "The time of day you're usually active"],
    ['Dezzmer Type', 'Your platform personality and how you use Dezzme'],
    ['Vibe Check', 'The anonymous community vote on your profile energy'],
    ['Repost', 'Putting a meme on your profile for Trackers to see'],
    ['Favourite', 'Privately saving a meme to your own vault'],
    ['Remix', 'Your own version of another meme, with credit'],
    ['Highlight', 'A saved set of slides on a profile or in a Pod'],
    ['Original audio', 'Audio from an uploaded video that others can reuse'],
    ['Collection', 'A folder of memes you build, public or private'],
    ['Memepedia', 'The meme encyclopedia'],
    ['Page', 'A brand, business or creator presence apart from a personal profile'],
    ['Partnership', 'A tag for a paid collaboration with a brand'],
    ['Ad', 'A post you promote with a button, a link and a run length'],
    ['Restrict', 'Quietly limit a Dezzmer\'s interaction with you'],
];

/* ── TROUBLESHOOTING ── */
$T['trouble'] = ['ri-tools-line', 'Troubleshooting', 'Quick fixes for common problems', [
    ['ri-wifi-off-line', 'Page will not load or is blank', "Check your connection, refresh, then log out and back in. If it still fails try another browser and clear site data."],
    ['ri-image-line', 'My meme will not upload', "Check file type, file size and connection. Try one file at a time. Report a bug through Feedback if it continues."],
    ['ri-play-circle-line', 'Videos will not play', "Check Autoplay in Settings, your connection and that the file is an MP4. Tap the video once to start it."],
    ['ri-volume-mute-line', 'No sound on a video', "Your device may be muted, the creator may have muted the video, or the attached sound has a low volume setting."],
    ['ri-mail-close-line', 'I did not get an email', "Check spam. Check the address. Wait a few minutes and request it again."],
    ['ri-user-forbid-line', 'I cannot track, message or repost someone', "You must track a Dezzmer first. If you already do, they may have blocked you or turned messages off."],
    ['ri-lock-line', 'I cannot see a profile or Pod', "It is private. Send a request. Blocked Dezzmers cannot see you either."],
    ['ri-notification-off-line', 'No notifications', "Check the switches in Settings, the Pod's notification option, and your browser's permission. See the Notifications tab."],
    ['ri-search-eye-line', 'I cannot find a post I made', "Check whether it was removed, whether it is inside a Pod, or search your own username."],
    ['ri-megaphone-line', 'My ad is not showing', "It may still be Pending, may have been Rejected, or may have finished its run. Check Activity for the review result."],
    ['ri-account-circle-line', 'Someone is pretending to be me', "Report the account as Suspicious or Bot Activity and give your DZ-ID. On the fake profile open About This Account to see its recent join date."],
    ['ri-hacker-line', 'My account was hacked', "Reset your password now, log out everywhere, check Linked accounts and your recovery email, then report it through Feedback."],
]];

/* ── FAQ ── */
$T['faq'] = ['ri-question-line', 'FAQ', 'Quick answers to common questions', [
    ['ri-question-line', 'How do I upload a meme?', "Open Upload, pick Image, Video or Text, choose your file, write a caption with hashtags and tap Post Content."],
    ['ri-question-line', "Why can't I message someone?", "You must track them first. This keeps messaging meaningful and cuts spam."],
    ['ri-question-line', "Why can't I see someone's reposts?", "Reposts are for Trackers only. Track them and, if private, wait for approval."],
    ['ri-question-line', 'How does Vibe Check work?', "Open a profile you track and choose Vibe Check. Pick one vibe. You can change it but not add more."],
    ['ri-question-line', 'Can I see who viewed my profile?', "Yes, in Insights. Dezzmers can hide their visit from you for a limited time."],
    ['ri-question-line', 'Favourite or Repost?', "Favourites are private. Reposts are visible to your Trackers."],
    ['ri-question-line', 'How do I join or create a Pod?', "Open Community. Tap Join on a Pod or tap Create Pod."],
    ['ri-question-line', 'Does Dezzme use my phone storage?', "No. Everything is stored on Dezzme."],
    ['ri-question-line', 'Is there a comment section?', "Not on regular memes. Comments exist on long-form videos, Pod discussions, Pod highlights, events and Memepedia."],
    ['ri-question-line', 'How do I change my username?', "In Settings. Your old usernames are saved in your account history and Dezzmers who track you are told."],
    ['ri-question-line', 'Can I have two accounts?', "Yes. Link them in Settings. Do not use a second account to avoid a ban."],
    ['ri-question-line', 'How do I stop someone contacting me?', "Block them. Use Restrict to limit them quietly or Mute to hide their posts."],
    ['ri-question-line', 'Why was my post removed?', "It probably broke community rules or a Pod's guidelines, and a moderator or Pod admin removed it."],
    ['ri-question-line', 'How do I get verified?', "Open Verification, choose a plan and a payment method, and confirm your age."],
    ['ri-question-line', 'How do I run an ad?', "Switch on Ad when you post, set the button text, link, colour and days, and submit it. See the Ads tab."],
    ['ri-question-line', 'How do I report something?', "Open its menu and tap Report. Choose a reason."],
    ['ri-question-line', 'How do I suggest a feature?', "Open Feedback, choose Feature, describe it and vote on others' ideas."],
    ['ri-question-line', 'What happens if I delete a post?', "It disappears everywhere it was shared, saved or collected."],
    ['ri-question-line', 'What is an AI label?', "A tag showing the content was made with AI."],
    ['ri-question-line', 'Why is my Activity empty?', "Activity shows today only and resets at midnight."],
]];

/* tab order and short names for the bar */
$tabbar = [
    'what' => 'What is Dezzme', 'start' => 'Getting Started', 'pages' => 'App Guide', 'posting' => 'Posting',
    'interact' => 'Interacting', 'audio' => 'Sounds', 'longform' => 'Long-form', 'tracking' => 'Tracking',
    'messages' => 'Messages', 'profile' => 'Profile', 'pods' => 'Pods', 'bizpages' => 'Business Pages',
    'memepedia' => 'Memepedia', 'ads' => 'Ads', 'notifs' => 'Notifications', 'money' => 'Subscriptions',
    'search' => 'Search', 'settings' => 'Settings', 'feedback' => 'Feedback', 'safety' => 'Safety',
    'vibe' => 'Vibe Check', 'privacy' => 'Privacy', 'vocab' => 'Vocabulary', 'trouble' => 'Troubleshooting', 'faq' => 'FAQ',
];
$tab_icons = [
    'what'=>'ri-information-line','start'=>'ri-rocket-line','pages'=>'ri-layout-grid-line','posting'=>'ri-upload-cloud-line',
    'interact'=>'ri-heart-3-line','audio'=>'ri-music-2-line','longform'=>'ri-movie-2-line','tracking'=>'ri-user-follow-line',
    'messages'=>'ri-chat-3-line','profile'=>'ri-user-3-line','pods'=>'ri-group-line','bizpages'=>'ri-pages-line',
    'memepedia'=>'ri-book-2-line','ads'=>'ri-megaphone-line','notifs'=>'ri-notification-3-line','money'=>'ri-vip-crown-line',
    'search'=>'ri-search-2-line','settings'=>'ri-settings-3-line','feedback'=>'ri-feedback-line','safety'=>'ri-shield-check-line',
    'vibe'=>'ri-sparkling-line','privacy'=>'ri-shield-line','vocab'=>'ri-book-open-line','trouble'=>'ri-tools-line','faq'=>'ri-question-line',
];
$start_tab = isset($_GET['tab']) && isset($tabbar[$_GET['tab']]) ? $_GET['tab'] : 'what';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Help and Support Center — Dezzme</title>
<!-- theme: applied before first paint so there is no dark flash in light mode (same key as every other Dezzme page) -->
<script>try{if(localStorage.getItem('dz_theme')==='light')document.documentElement.setAttribute('data-theme','light');}catch(e){}</script>

<link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">

<style>
*, *::before, *::after { box-sizing:border-box; margin:0; padding:0; font-family:'Poppins', sans-serif; }
button, a { cursor:pointer; outline:none; -webkit-tap-highlight-color:transparent; }
a { text-decoration:none; color:inherit; }
[class^="ri-"], [class*=" ri-"], [class^="ri-"]::before, [class*=" ri-"]::before {
    font-family:'remixicon' !important; font-style:normal; font-weight:normal; line-height:1; speak:none; text-transform:none; -webkit-font-smoothing:antialiased;
}

:root{
    --fg:       255,255,255;   /* R,G,B of the text-on-surface tint used for lines + soft fills (dark theme = white) */
    --ui-text:  #ffffff;
    --red:      #FF2D2D;
    --bg:       linear-gradient(-45deg,#010218,#070924,#070924);
    --surface:  rgba(var(--fg),0.05);
    --line:     rgba(var(--fg),0.09);
    --line2:    rgba(var(--fg),0.14);
    --text:     #ffffff;
    --muted:    rgba(var(--fg),0.45);
    --sub:      rgba(var(--fg),0.62);

    --max-w:    580px;
    --topbar-h: 54px;
    --pad:      clamp(9px, 3vw, 16px);
    --ctl-h:    clamp(30px, 8vw, 36px);
    --r:        5px;
}
@media (max-width:490px){ :root{ --topbar-h:48px; } }
@media (max-width:380px){ :root{ --topbar-h:44px; } }

html{ scroll-behavior:smooth; }
@media (max-width:768px){
    html, body, *{ scrollbar-width:none !important; -ms-overflow-style:none !important; }
    *::-webkit-scrollbar, html::-webkit-scrollbar, body::-webkit-scrollbar{ display:none !important; width:0 !important; height:0 !important; background:transparent !important; }
}
body{ background:var(--bg); background-attachment:fixed; color:var(--text); min-height:100vh; display:flex; justify-content:center; overflow-x:hidden; -webkit-font-smoothing:antialiased; }
body.loading{ overflow:hidden; }
.hp-lane{ width:100%; max-width:var(--max-w); min-height:100vh; display:flex; flex-direction:column; padding-bottom:72px; }
@media (min-width:801px){ .hp-lane{ border-left:1px solid var(--line); border-right:1px solid var(--line); } }
@media (max-width:800px){ .hp-lane{ max-width:100%; } }

/* TOPBAR — X on the left, title on the right; grey line is its own layer so it shows on EVERY screen size */
.hp-topbar{ position:sticky; top:0; z-index:500; height:var(--topbar-h); display:flex; align-items:center; gap:2px; padding:0 10px; background:var(--bg); background-attachment:fixed; }
.hp-topbar::after{ content:''; position:absolute; left:0; right:0; bottom:0; height:1px; background:rgba(var(--fg),0.14); pointer-events:none; z-index:2; }
.hp-x{ width:34px; height:34px; background:none; border:none; color:var(--ui-text); display:flex; align-items:center; justify-content:center; opacity:.9; transition:opacity .2s,color .2s; padding:0; flex-shrink:0; }
.hp-x:hover{ opacity:1; color:var(--red); }
.hp-x svg{ width:22px; height:22px; display:block; }
.hp-title{ flex:1; min-width:0; font-size:.92rem; font-weight:700; color:var(--ui-text); letter-spacing:-.1px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; text-align:right; margin:0 6px 0 10px; }
@media (max-width:490px){ .hp-x{ width:30px; height:30px; } .hp-title{ font-size:.84rem; } }
@media (max-width:380px){ .hp-title{ font-size:.8rem; } }

/* reveal */
.rv{ opacity:0; }
body.ready .rv{ animation:hpIn .45s cubic-bezier(.22,1,.36,1) both; animation-delay:calc(var(--i,0) * 50ms); }
@keyframes hpIn{ from{ opacity:0; transform:translateY(8px); } to{ opacity:1; transform:none; } }
@media (prefers-reduced-motion:reduce){ body.ready .rv{ animation:none; opacity:1; } }

/* HERO */
.hero{ padding:clamp(14px,4.5vw,24px) var(--pad) clamp(14px,4vw,20px); text-align:center; }
.hero-badge{ display:inline-flex; align-items:center; gap:6px; height:clamp(22px,6vw,26px); padding:0 11px; border-radius:4px; background:rgba(255,45,45,.12); font-size:clamp(.56rem,1.9vw,.64rem); font-weight:700; color:var(--red); letter-spacing:.06em; text-transform:uppercase; margin-bottom:clamp(10px,3vw,16px); }
.hero h1{ font-size:clamp(1.15rem,5.4vw,1.65rem); font-weight:700; line-height:1.15; letter-spacing:-.3px; margin-bottom:9px; color:var(--ui-text); }
.hero-sub{ font-size:clamp(.68rem,2.3vw,.82rem); color:var(--muted); line-height:1.6; margin-bottom:clamp(14px,4vw,20px); }
.help-search{ position:relative; display:flex; align-items:center; width:100%; height:var(--ctl-h); background:var(--surface); border:1px solid var(--line2); border-radius:var(--r); transition:border-color .2s,background .2s,box-shadow .2s; }
.help-search:focus-within{ border-color:rgba(255,45,45,.7); background:rgba(var(--fg),.07); box-shadow:0 0 0 3px rgba(255,45,45,.12); }
.help-search i.search-ic{ padding:0 0 0 11px; font-size:1rem; color:var(--muted); flex-shrink:0; }
.help-search input{ flex:1; min-width:0; height:100%; background:none; border:none; outline:none; font-size:.76rem; color:var(--ui-text); padding:0 10px; -webkit-appearance:none; appearance:none; }
.help-search input::placeholder{ color:var(--muted); }
.help-search-clear{ display:none; align-items:center; justify-content:center; width:24px; height:24px; margin-right:6px; flex-shrink:0; background:rgba(var(--fg),.1); border:none; border-radius:4px; color:var(--sub); font-size:.95rem; transition:background .2s,color .2s; }
.help-search-clear:hover{ background:rgba(var(--fg),.2); color:var(--ui-text); }
.help-search-clear.visible{ display:flex; }

/* CENTRE BUTTONS */
.centre-btns{ display:grid; grid-template-columns:1fr 1fr; gap:8px; padding:0 var(--pad); margin-top:14px; }
@media (max-width:420px){ .centre-btns{ grid-template-columns:1fr; gap:6px; } }
.centre-btn{ display:flex; align-items:center; gap:10px; padding:clamp(9px,2.8vw,12px) clamp(10px,3vw,13px); background:var(--surface); border:1px solid var(--line2); border-radius:6px; transition:border-color .2s,background .2s,transform .15s; }
.centre-btn:hover{ background:rgba(var(--fg),.09); border-color:rgba(255,45,45,.55); }
.centre-btn:active{ transform:scale(.98); }
.cb-ico{ width:clamp(28px,7.6vw,34px); height:clamp(28px,7.6vw,34px); border-radius:6px; flex-shrink:0; background:rgba(255,45,45,.14); color:var(--red); display:flex; align-items:center; justify-content:center; font-size:clamp(.92rem,2.9vw,1.05rem); }
.cb-t{ font-size:clamp(.7rem,2.3vw,.76rem); font-weight:700; line-height:1.25; color:var(--ui-text); }
.cb-d{ font-size:clamp(.58rem,1.9vw,.64rem); color:var(--muted); margin-top:2px; line-height:1.4; }
.cb-go{ margin-left:auto; color:var(--muted); font-size:1rem; flex-shrink:0; }
@media (max-width:380px){ .centre-btn{ gap:9px; padding:8px 10px; } .cb-t{ font-size:.66rem; } .cb-d{ font-size:.55rem; } }

/* TAB BAR with scroll buttons */
.tabs-wrap{ position:sticky; top:calc(var(--topbar-h) - 1px); z-index:400; display:flex; align-items:center; margin-top:14px; background:var(--bg); background-attachment:fixed; border-top:1px solid var(--line); border-bottom:1px solid var(--line); }
.hp-chips{ flex:1; min-width:0; display:flex; gap:6px; align-items:center; padding:7px var(--pad); overflow-x:auto; scrollbar-width:none; -ms-overflow-style:none; -webkit-overflow-scrolling:touch; scroll-behavior:smooth; }
.hp-chips::-webkit-scrollbar{ display:none; }
.tab-arrow{ display:none; flex:0 0 auto; width:26px; height:26px; margin:0 4px; align-items:center; justify-content:center; background:rgba(var(--fg),.1); border:1px solid var(--line2); border-radius:5px; color:var(--ui-text); font-size:1rem; transition:background .2s,opacity .2s; }
.tab-arrow:hover{ background:rgba(var(--fg),.2); }
.tab-arrow:disabled{ opacity:.25; cursor:default; }
@media (min-width:801px){ .tab-arrow{ display:flex; } .hp-chips{ padding-left:4px; padding-right:4px; } }

.quick-chip{ display:flex; align-items:center; gap:6px; flex-shrink:0; height:clamp(25px,7vw,29px); padding:0 12px; background:rgba(var(--fg),.1); border:none; border-radius:4px; font-size:.72rem; font-weight:600; color:rgba(var(--fg),.75); white-space:nowrap; transition:background .2s,color .2s,transform .15s; }
.quick-chip i{ font-size:.92rem; color:var(--red); }
.quick-chip:hover{ background:rgba(var(--fg),.2); color:var(--ui-text); }
.quick-chip:active{ transform:scale(.97); }
.quick-chip.active{ background:var(--red); color:#000; }
.quick-chip.active i{ color:#000; }
@media (max-width:490px){ .quick-chip{ font-size:.66rem; padding:0 10px; gap:5px; } .quick-chip i{ font-size:.84rem; } .hp-chips{ gap:5px; padding:6px var(--pad); } }
@media (max-width:380px){ .quick-chip{ font-size:.62rem; padding:0 8px; height:24px; gap:4px; } .quick-chip i{ font-size:.78rem; } .hp-chips{ gap:4px; } }
body.searching .tabs-wrap{ opacity:.45; pointer-events:none; }

.no-results{ display:none; text-align:center; padding:60px 20px; color:var(--muted); }
.no-results i{ font-size:2.1rem; display:block; margin-bottom:12px; color:var(--red); opacity:.85; }
.no-results p{ font-size:.8rem; }

/* PANELS */
.help-main{ padding:0 var(--pad); }
.tab-panel{ display:none; margin-top:clamp(16px,4.6vw,24px); }
.tab-panel.active{ display:block; animation:hpIn .35s cubic-bezier(.22,1,.36,1) both; }
.section-eyebrow{ display:flex; align-items:center; gap:12px; padding:0 0 12px; }
.section-icon-wrap{ width:clamp(28px,7.6vw,36px); height:clamp(28px,7.6vw,36px); border-radius:6px; background:var(--surface); display:flex; align-items:center; justify-content:center; font-size:clamp(.82rem,2.6vw,1rem); color:var(--red); flex-shrink:0; }
.section-heading{ font-size:clamp(.82rem,2.8vw,.96rem); font-weight:700; letter-spacing:-.1px; color:var(--ui-text); }
.section-desc{ font-size:clamp(.58rem,1.9vw,.7rem); color:var(--muted); margin-top:2px; }

.info-list{ display:flex; flex-direction:column; border-top:1px solid var(--line); }
.info-row{ display:flex; align-items:flex-start; gap:clamp(10px,3vw,14px); padding:clamp(11px,3.2vw,15px) 0; border-bottom:1px solid var(--line); }
.info-list .info-row:last-child{ border-bottom:none; }
.info-icon{ width:clamp(27px,7.4vw,34px); height:clamp(27px,7.4vw,34px); border-radius:6px; background:var(--surface); display:flex; align-items:center; justify-content:center; flex-shrink:0; color:var(--red); font-size:clamp(.78rem,2.5vw,.96rem); }
.info-content{ flex:1; min-width:0; }
.info-title{ font-size:clamp(.74rem,2.5vw,.84rem); font-weight:600; margin-bottom:3px; color:var(--ui-text); }
.info-text{ font-size:clamp(.64rem,2.1vw,.74rem); color:var(--muted); line-height:1.65; }
.info-text strong, .info-text em{ color:var(--text); }

.accordion{ display:flex; flex-direction:column; border-top:1px solid var(--line); }
.acc-item{ border-bottom:1px solid var(--line); }
.accordion .acc-item:last-child{ border-bottom:none; }
.acc-trigger{ display:flex; align-items:center; gap:clamp(9px,2.8vw,12px); width:100%; padding:clamp(10px,3vw,14px) 0; background:none; border:none; color:var(--text); text-align:left; }
.acc-icon{ width:clamp(27px,7.4vw,34px); height:clamp(27px,7.4vw,34px); border-radius:6px; background:var(--surface); display:flex; align-items:center; justify-content:center; font-size:clamp(.78rem,2.5vw,.96rem); color:var(--red); flex-shrink:0; }
.acc-trigger-text{ flex:1; min-width:0; font-size:clamp(.72rem,2.4vw,.82rem); font-weight:600; line-height:1.35; }
.acc-chevron{ font-size:1.05rem; color:var(--muted); flex-shrink:0; transition:transform .3s cubic-bezier(.22,1,.36,1),color .2s; }
.acc-trigger:hover .acc-chevron{ color:var(--ui-text); }
.acc-item.open .acc-chevron{ transform:rotate(180deg); color:var(--red); }
.acc-body{ display:grid; grid-template-rows:0fr; transition:grid-template-rows .32s cubic-bezier(.22,1,.36,1); }
.acc-item.open .acc-body{ grid-template-rows:1fr; }
.acc-in{ min-height:0; overflow:hidden; }
.acc-txt{ padding:0 0 15px clamp(36px,10.4vw,46px); font-size:clamp(.64rem,2.1vw,.75rem); color:var(--muted); line-height:1.75; }
.acc-txt strong{ color:var(--text); font-weight:600; }
.acc-txt em{ color:rgba(var(--fg),.75); }
.acc-txt ul, .acc-txt ol{ padding-left:18px; margin:4px 0; }
.acc-txt li{ margin:3px 0; padding-left:2px; }
.acc-txt li::marker{ color:var(--red); }

.subhead{ font-size:.6rem; font-weight:700; color:var(--muted); letter-spacing:.1em; text-transform:uppercase; margin:20px 0 10px; }
.vibe-grid{ display:flex; flex-wrap:wrap; gap:6px; }
.vibe-pill{ display:flex; align-items:center; gap:7px; height:clamp(26px,7.2vw,31px); padding:0 11px; background:rgba(var(--fg),.07); border-radius:5px; font-size:clamp(.6rem,2vw,.72rem); font-weight:500; color:var(--ui-text); }
.vibe-pill span{ font-size:.95rem; flex-shrink:0; }
.privacy-note{ font-size:clamp(.64rem,2.1vw,.74rem); color:var(--muted); line-height:1.6; margin-bottom:14px; }
.privacy-table-wrap{ overflow-x:auto; border-radius:6px; border:1px solid var(--line); }
.privacy-table{ width:100%; border-collapse:collapse; font-size:clamp(.62rem,2vw,.74rem); }
.privacy-table th{ text-align:left; padding:10px 12px; font-size:.58rem; font-weight:700; letter-spacing:.06em; text-transform:uppercase; color:var(--muted); background:var(--surface); border-bottom:1px solid var(--line); white-space:nowrap; }
.privacy-table td{ padding:11px 12px; border-bottom:1px solid var(--line); vertical-align:top; white-space:nowrap; }
.privacy-table tr:last-child td{ border-bottom:none; }
.priv-feature{ font-weight:600; color:var(--ui-text); } .priv-yes{ color:var(--red); } .priv-no{ color:var(--muted); } .priv-cond{ color:rgba(var(--fg),.75); }
.vocab-list{ border-top:1px solid var(--line); }
.vocab-row{ display:flex; gap:12px; align-items:baseline; padding:clamp(10px,3vw,13px) 0; border-bottom:1px solid var(--line); }
.vocab-list .vocab-row:last-child{ border-bottom:none; }
.vocab-term{ flex:0 0 clamp(88px,28vw,120px); font-size:clamp(.72rem,2.4vw,.82rem); font-weight:700; color:var(--red); }
.vocab-def{ flex:1; min-width:0; font-size:clamp(.64rem,2.1vw,.74rem); color:var(--muted); line-height:1.55; }
.safety-cta{ margin-top:14px; display:grid; gap:8px; }
.hl{ background:rgba(255,45,45,.24); color:var(--ui-text); border-radius:3px; padding:0 2px; }
.hp-foot{ margin:36px var(--pad) 0; padding-top:18px; border-top:1px solid var(--line); text-align:center; font-size:.66rem; color:var(--muted); line-height:1.7; }
.hp-foot a{ color:var(--red); font-weight:600; }

/* LOADER — small fragmented spinner */
#page-loader{ position:fixed; inset:0; z-index:9999; background:var(--bg); background-attachment:fixed; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:12px; opacity:1; transition:opacity .4s ease,visibility .4s ease; }
#page-loader.hide{ opacity:0; visibility:hidden; pointer-events:none; }
.frag{ position:relative; width:28px; height:28px; }
.frag i{ position:absolute; left:50%; top:0; width:2.5px; height:7px; margin-left:-1.25px; border-radius:2px; background:var(--red); transform-origin:50% 14px; transform:rotate(calc(var(--n) * 30deg)); opacity:.14; animation:fragFade 1.2s linear infinite; animation-delay:calc(var(--n) * -0.1s); }
@keyframes fragFade{ 0%{ opacity:1; } 100%{ opacity:.14; } }
.loader-label{ font-size:.64rem; font-weight:500; color:var(--muted); letter-spacing:.02em; }

/* ═══════════════════════════════════════════════════════════════
   RESPONSIVE ONLY — everything 10% bigger (desktop untouched)
   Same scale as the About This Account page. Kept near the end so it wins over the rules above.
═══════════════════════════════════════════════════════════════ */
@media (max-width:800px){
    :root{
        --topbar-h:59px;
        --pad:clamp(10px, 3.3vw, 17.6px);
        --ctl-h:clamp(33px, 8.8vw, 39.6px);
        --r:5.5px;
    }

    /* topbar */
    .hp-topbar{ padding:0 11px; gap:2px; }
    .hp-x{ width:37px; height:37px; }
    .hp-x svg{ width:24px; height:24px; }
    .hp-title{ font-size:1.01rem; margin:0 7px 0 11px; }

    /* hero */
    .hero{ padding:clamp(15px, 4.95vw, 26.4px) var(--pad) clamp(15px, 4.4vw, 22px); }
    .hero-badge{ height:clamp(24px, 6.6vw, 28.6px); padding:0 12px; gap:6.6px; font-size:clamp(.62rem, 2.09vw, .7rem); margin-bottom:clamp(11px, 3.3vw, 17.6px); }
    .hero h1{ font-size:clamp(1.27rem, 5.94vw, 1.8rem); margin-bottom:10px; }
    .hero-sub{ font-size:clamp(.75rem, 2.53vw, .9rem); margin-bottom:clamp(15px, 4.4vw, 22px); }
    .help-search i.search-ic{ padding-left:12px; font-size:1.1rem; }
    .help-search input{ font-size:.84rem; padding:0 11px; }
    .help-search-clear{ width:26px; height:26px; margin-right:7px; font-size:1.05rem; }

    /* centre buttons */
    .centre-btns{ gap:8.8px; margin-top:15px; }
    .centre-btn{ gap:11px; padding:clamp(10px, 3.08vw, 13px) clamp(11px, 3.3vw, 14px); border-radius:6.6px; }
    .cb-ico{ width:clamp(31px, 8.36vw, 37px); height:clamp(31px, 8.36vw, 37px); border-radius:6.6px; font-size:clamp(1rem, 3.2vw, 1.16rem); }
    .cb-t{ font-size:clamp(.77rem, 2.53vw, .84rem); }
    .cb-d{ font-size:clamp(.64rem, 2.09vw, .7rem); margin-top:2.2px; }
    .cb-go{ font-size:1.1rem; }

    /* tabs */
    .tabs-wrap{ margin-top:15px; }
    .hp-chips{ gap:6.6px; padding:8px var(--pad); }
    .quick-chip{ height:clamp(27.5px, 7.7vw, 32px); padding:0 13px; gap:6.6px; font-size:.79rem; border-radius:4.4px; }
    .quick-chip i{ font-size:1.01rem; }

    /* panels */
    .tab-panel{ margin-top:clamp(18px, 5vw, 26px); }
    .section-eyebrow{ gap:13px; padding-bottom:13px; }
    .section-icon-wrap{ width:clamp(31px, 8.36vw, 39.6px); height:clamp(31px, 8.36vw, 39.6px); border-radius:6.6px; font-size:clamp(.9rem, 2.86vw, 1.1rem); }
    .section-heading{ font-size:clamp(.9rem, 3.08vw, 1.056rem); }
    .section-desc{ font-size:clamp(.64rem, 2.09vw, .77rem); margin-top:2.2px; }

    .info-row{ gap:clamp(11px, 3.3vw, 15px); padding:clamp(12px, 3.52vw, 16.5px) 0; }
    .info-icon{ width:clamp(30px, 8.14vw, 37.4px); height:clamp(30px, 8.14vw, 37.4px); border-radius:6.6px; font-size:clamp(.86rem, 2.75vw, 1.06rem); }
    .info-title{ font-size:clamp(.81rem, 2.75vw, .92rem); margin-bottom:3.3px; }
    .info-text{ font-size:clamp(.7rem, 2.31vw, .81rem); }

    .acc-trigger{ gap:clamp(10px, 3.08vw, 13px); padding:clamp(11px, 3.3vw, 15.4px) 0; }
    .acc-icon{ width:clamp(30px, 8.14vw, 37.4px); height:clamp(30px, 8.14vw, 37.4px); border-radius:6.6px; font-size:clamp(.86rem, 2.75vw, 1.06rem); }
    .acc-trigger-text{ font-size:clamp(.79rem, 2.64vw, .9rem); }
    .acc-chevron{ font-size:1.155rem; }
    .acc-txt{ padding:0 0 16.5px clamp(40px, 11.44vw, 50.6px); font-size:clamp(.7rem, 2.31vw, .825rem); }
    .acc-txt ul, .acc-txt ol{ padding-left:20px; margin:4.4px 0; }

    .subhead{ font-size:.66rem; margin:22px 0 11px; }
    .vibe-grid{ gap:6.6px; }
    .vibe-pill{ height:clamp(28.6px, 7.92vw, 34px); padding:0 12px; gap:7.7px; font-size:clamp(.66rem, 2.2vw, .79rem); border-radius:5.5px; }
    .vibe-pill span{ font-size:1.05rem; }
    .privacy-note{ font-size:clamp(.7rem, 2.31vw, .81rem); margin-bottom:15px; }
    .privacy-table{ font-size:clamp(.68rem, 2.2vw, .81rem); }
    .privacy-table th{ padding:11px 13px; font-size:.64rem; }
    .privacy-table td{ padding:12px 13px; }
    .vocab-row{ gap:13px; padding:clamp(11px, 3.3vw, 14.3px) 0; }
    .vocab-term{ flex-basis:clamp(97px, 30.8vw, 132px); font-size:clamp(.79rem, 2.64vw, .9rem); }
    .vocab-def{ font-size:clamp(.7rem, 2.31vw, .81rem); }
    .safety-cta{ margin-top:15px; gap:8.8px; }
    .hp-foot{ font-size:.73rem; margin-top:40px; padding-top:20px; }

    /* loader */
    .frag{ width:31px; height:31px; }
    .loader-label{ font-size:.7rem; }
}
/* re-assert the smaller-screen steps after the 10% block, same as the About page */
@media (max-width:490px){
    :root{ --topbar-h:53px; }
    .hp-x{ width:33px; height:33px; }
    .hp-title{ font-size:.92rem; }
    .quick-chip{ font-size:.73rem; padding:0 11px; gap:5.5px; }
    .quick-chip i{ font-size:.92rem; }
    .hp-chips{ gap:5.5px; padding:7px var(--pad); }
}
@media (max-width:420px){ .centre-btns{ grid-template-columns:1fr; gap:6.6px; } }
@media (max-width:380px){
    :root{ --topbar-h:48px; }
    .hp-title{ font-size:.88rem; }
    .quick-chip{ font-size:.68rem; padding:0 9px; height:26px; gap:4.4px; }
    .quick-chip i{ font-size:.86rem; }
    .hp-chips{ gap:4.4px; }
    .centre-btn{ gap:10px; padding:9px 11px; }
    .cb-t{ font-size:.73rem; }
    .cb-d{ font-size:.6rem; }
}

/* ═══════════ LIGHT MODE — <html data-theme="light"> (dark mode is never touched) ═══════════
   Same palette as the About, Community, Games, Algorithm and Upload pages. The red accent stays the same in both themes. */
:root[data-theme="light"]{
    color-scheme:light;
    --fg:6,7,26;
    --ui-text:#0b0d24;
    --text:#0b0d24;
    --bg:linear-gradient(-45deg,#ffffff,#f4f5fb,#f4f5fb);
    --surface:rgba(6,7,26,.05);
    --line:rgba(6,7,26,.1);
    --line2:rgba(6,7,26,.2);
    --muted:rgba(6,7,26,.52);
    --sub:rgba(6,7,26,.66);
}
</style>
<noscript><style>.rv{opacity:1 !important}#page-loader{display:none}body.loading{overflow:auto}.tab-panel{display:block}</style></noscript>
</head>
<body class="loading">

<div id="page-loader" role="status" aria-label="Loading">
    <div class="frag" aria-hidden="true"><?php for ($n = 0; $n < 12; $n++): ?><i style="--n:<?php echo $n; ?>"></i><?php endfor; ?></div>
    <div class="loader-label">Loading</div>
</div>

<div class="hp-lane">

    <header class="hp-topbar">
        <button type="button" class="hp-x" onclick="hpClose()" aria-label="Close">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19"/></svg>
        </button>
        <span class="hp-title">Help and Support Center</span>
    </header>

    <section class="hero rv" style="--i:0;">
        <div class="hero-badge"><i class="ri-customer-service-2-line"></i> Help and Support Center</div>
        <h1>How can we help you?</h1>
        <p class="hero-sub">Pick a topic tab below, open a dropdown for the full answer, or search everything at once.</p>
        <div class="help-search">
            <i class="ri-search-line search-ic"></i>
            <input type="text" id="searchInput" placeholder="Search for anything…" autocomplete="off">
            <button type="button" class="help-search-clear" id="searchClear" aria-label="Clear search"><i class="ri-close-line"></i></button>
        </div>
    </section>

    <div class="centre-btns rv" style="--i:1;">
        <a class="centre-btn" href="help_center.php?tab=help">
            <span class="cb-ico"><i class="ri-lifebuoy-line"></i></span>
            <span><div class="cb-t">Help Centre</div><div class="cb-d">Contact support, appeals and account recovery</div></span>
            <i class="ri-arrow-right-s-line cb-go"></i>
        </a>
        <a class="centre-btn" href="help_center.php?tab=safety">
            <span class="cb-ico"><i class="ri-shield-keyhole-line"></i></span>
            <span><div class="cb-t">Scam and Privacy Protection Centre</div><div class="cb-d">Spot scams and protect your information</div></span>
            <i class="ri-arrow-right-s-line cb-go"></i>
        </a>
    </div>

    <div class="tabs-wrap rv" style="--i:2;">
        <button type="button" class="tab-arrow" id="tabLeft" aria-label="Scroll tabs left" onclick="scrollTabs(-1)"><i class="ri-arrow-left-s-line"></i></button>
        <nav class="hp-chips" id="hpChips" aria-label="Help topics">
            <?php foreach ($tabbar as $id => $label): ?>
            <button type="button" class="quick-chip<?php echo $id === $start_tab ? ' active' : ''; ?>" data-tab="<?php echo $id; ?>" onclick="openTab('<?php echo $id; ?>')"><i class="<?php echo $tab_icons[$id]; ?>"></i><span><?php echo $label; ?></span></button>
            <?php endforeach; ?>
        </nav>
        <button type="button" class="tab-arrow" id="tabRight" aria-label="Scroll tabs right" onclick="scrollTabs(1)"><i class="ri-arrow-right-s-line"></i></button>
    </div>

    <div class="no-results" id="noResults"><i class="ri-search-2-line"></i><p>No results found. Try a different search.</p></div>

    <main class="help-main" id="helpMain">

        <?php foreach ($T as $id => $tab): ?>
        <section class="tab-panel<?php echo $id === $start_tab ? ' active' : ''; ?>" id="tab-<?php echo $id; ?>">
            <?php hp_head($tab[0], $tab[1], $tab[2]); hp_acc($tab[3], false); ?>
            <?php if ($id === 'safety'): ?>
            <div class="safety-cta">
                <a class="centre-btn" href="help_center.php?tab=safety">
                    <span class="cb-ico"><i class="ri-shield-keyhole-line"></i></span>
                    <span><div class="cb-t">Open the Scam and Privacy Protection Centre</div><div class="cb-d">Scam examples, privacy checklists and what to do if something goes wrong</div></span>
                    <i class="ri-arrow-right-s-line cb-go"></i>
                </a>
                <a class="centre-btn" href="help_center.php?tab=help">
                    <span class="cb-ico"><i class="ri-lifebuoy-line"></i></span>
                    <span><div class="cb-t">Open the Help Centre</div><div class="cb-d">Contact support, appeals and account recovery</div></span>
                    <i class="ri-arrow-right-s-line cb-go"></i>
                </a>
            </div>
            <?php endif; ?>
        </section>
        <?php endforeach; ?>

        <section class="tab-panel" id="tab-vibe">
            <?php hp_head('ri-sparkling-line', 'Vibe Check', 'The feature nobody else has'); hp_rows($vibe_info); ?>
            <p class="subhead">Available vibes</p>
            <div class="vibe-grid">
                <?php foreach ($vibes as $v): ?><div class="vibe-pill"><span><?php echo $v[0]; ?></span> <?php echo $v[1]; ?></div><?php endforeach; ?>
            </div>
        </section>

        <section class="tab-panel" id="tab-privacy">
            <?php hp_head('ri-shield-line', 'Privacy System', 'Who can see what and when'); ?>
            <p class="privacy-note">On Dezzme, privacy is mostly decided by your relationship with someone, not just a settings toggle. A private account adds an approval step on top.</p>
            <div class="privacy-table-wrap">
                <table class="privacy-table">
                    <thead><tr><th>Content</th><th>Anyone</th><th>Trackers Only</th><th>Only You</th></tr></thead>
                    <tbody>
                    <?php foreach ($privacy as $p): ?>
                        <tr>
                            <td class="priv-feature"><?php echo $p[0]; ?></td>
                            <td class="priv-<?php echo $p[1][1]; ?>"><?php echo $p[1][0]; ?></td>
                            <td class="priv-<?php echo $p[2][1]; ?>"><?php echo $p[2][0]; ?></td>
                            <td class="priv-<?php echo $p[3][1]; ?>"><?php echo $p[3][0]; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="tab-panel" id="tab-vocab">
            <?php hp_head('ri-book-open-line', 'Platform Vocabulary', 'Dezzme speaks its own language'); ?>
            <div class="vocab-list">
                <?php foreach ($vocab as $v): ?><div class="vocab-row"><div class="vocab-term"><?php echo $v[0]; ?></div><div class="vocab-def"><?php echo $v[1]; ?></div></div><?php endforeach; ?>
            </div>
        </section>

    </main>

    <div class="hp-foot">
        Still stuck? Open the <a href="help_center.php?tab=help">Help Centre</a> or the
        <a href="help_center.php?tab=safety">Scam and Privacy Protection Centre</a>, or send a message through Feedback.
    </div>

</div>

<script>
// ── keep the theme in sync if it is switched in another tab ──
window.addEventListener('storage', function(e){
    if(e.key !== 'dz_theme') return;
    if(e.newValue === 'light') document.documentElement.setAttribute('data-theme','light');
    else document.documentElement.removeAttribute('data-theme');
});

function hpClose(){
    if (document.referrer && document.referrer.indexOf(window.location.host) !== -1) history.back();
    else location.href = 'profile.php';
}

/* loader */
(function(){
    var el = document.getElementById('page-loader'), shownAt = Date.now(), done = false, MIN = 500;
    function reveal(){
        if (done) return; done = true;
        setTimeout(function(){
            document.body.classList.remove('loading');
            document.body.classList.add('ready');
            if (el){ el.classList.add('hide'); setTimeout(function(){ if (el.parentNode) el.remove(); }, 450); }
            updateArrows();
        }, Math.max(0, MIN - (Date.now() - shownAt)));
    }
    if (document.readyState === 'complete') reveal(); else window.addEventListener('load', reveal);
    setTimeout(reveal, 3000);
})();

/* accordion */
function toggleAcc(btn){
    var item = btn.closest('.acc-item'), isOpen = item.classList.contains('open');
    btn.closest('.accordion').querySelectorAll('.acc-item').forEach(function(i){ i.classList.remove('open'); });
    if (!isOpen) item.classList.add('open');
}

/* tab bar scroll buttons (normal / desktop view) */
var chips = document.getElementById('hpChips'), tabL = document.getElementById('tabLeft'), tabR = document.getElementById('tabRight');
function updateArrows(){
    if (!chips) return;
    tabL.disabled = chips.scrollLeft <= 2;
    tabR.disabled = chips.scrollLeft + chips.clientWidth >= chips.scrollWidth - 2;
}
function scrollTabs(dir){
    chips.scrollBy({ left: dir * Math.max(160, chips.clientWidth * 0.7), behavior:'smooth' });
}
chips.addEventListener('scroll', updateArrows, { passive:true });
window.addEventListener('resize', updateArrows);
chips.addEventListener('wheel', function(e){
    if (Math.abs(e.deltaY) > Math.abs(e.deltaX) && chips.scrollWidth > chips.clientWidth){
        e.preventDefault(); chips.scrollLeft += e.deltaY;
    }
}, { passive:false });

/* tabs */
function openTab(id, silent){
    if (document.body.classList.contains('searching')) clearSearchBox();
    var panel = document.getElementById('tab-' + id);
    if (!panel) return;
    document.querySelectorAll('.tab-panel').forEach(function(p){ p.classList.remove('active'); });
    panel.classList.add('active');
    document.querySelectorAll('.quick-chip').forEach(function(c){
        var on = c.dataset.tab === id;
        c.classList.toggle('active', on);
        if (on) chips.scrollTo({ left: c.offsetLeft - (chips.clientWidth - c.offsetWidth) / 2, behavior:'smooth' });
    });
    panel.querySelectorAll('.acc-item.open').forEach(function(i){ i.classList.remove('open'); });
    var first = panel.querySelector('.acc-item'); if (first && !silent) first.classList.add('open');
    if (!silent){
        try { history.replaceState(null, '', '?tab=' + id); } catch(e){}
        var wrap = document.querySelector('.tabs-wrap');
        var target = wrap.getBoundingClientRect().top + window.pageYOffset - document.querySelector('.hp-topbar').offsetHeight;
        if (window.pageYOffset > target) window.scrollTo({ top: target, behavior:'smooth' });
    }
}
(function(){ var f = document.querySelector('.tab-panel.active .acc-item'); if (f) f.classList.add('open'); })();

/* search across all tabs */
var searchInput = document.getElementById('searchInput'), searchClear = document.getElementById('searchClear');
var noResults = document.getElementById('noResults'), helpMain = document.getElementById('helpMain');
function escRe(s){ return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }
var ITEMS = '.acc-item, .info-row, .vocab-row, .vibe-pill, .privacy-table tbody tr';

searchInput.addEventListener('input', function(){
    var q = this.value.trim().toLowerCase();
    searchClear.classList.toggle('visible', q.length > 0);
    if (!q){ clearSearch(); return; }
    document.body.classList.add('searching');
    var any = false;
    document.querySelectorAll('.tab-panel').forEach(function(p){
        removeHighlights(p);
        var hit = false;
        p.querySelectorAll(ITEMS).forEach(function(i){
            var m = i.textContent.toLowerCase().indexOf(q) > -1;
            i.style.display = m ? '' : 'none';
            if (i.classList.contains('acc-item')){ i.classList.toggle('open', m); }
            if (m) hit = true;
        });
        p.style.display = hit ? 'block' : 'none';
        if (hit){ any = true; highlightText(p, q); }
    });
    noResults.style.display = any ? 'none' : 'block';
    helpMain.style.display = any ? 'block' : 'none';
});
searchClear.addEventListener('click', function(){ clearSearchBox(); searchInput.focus(); });
function clearSearchBox(){ searchInput.value = ''; searchClear.classList.remove('visible'); clearSearch(); }
function clearSearch(){
    document.body.classList.remove('searching');
    document.querySelectorAll('.tab-panel').forEach(function(p){
        p.style.display = ''; removeHighlights(p);
        p.querySelectorAll(ITEMS).forEach(function(i){ i.style.display = ''; });
        p.querySelectorAll('.acc-item.open').forEach(function(i){ i.classList.remove('open'); });
    });
    var a = document.querySelector('.tab-panel.active .acc-item'); if (a) a.classList.add('open');
    noResults.style.display = 'none'; helpMain.style.display = '';
}
function highlightText(el, q){
    var re = new RegExp(escRe(q), 'gi'), walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT), nodes = [];
    while (walker.nextNode()) nodes.push(walker.currentNode);
    nodes.forEach(function(node){
        var p = node.parentNode;
        if (!p || ['SCRIPT','STYLE','MARK'].indexOf(p.tagName) > -1) return;
        var text = node.nodeValue; re.lastIndex = 0;
        if (!re.test(text)) return; re.lastIndex = 0;
        var frag = document.createDocumentFragment(), last = 0, m;
        while ((m = re.exec(text)) !== null){
            if (m.index > last) frag.appendChild(document.createTextNode(text.slice(last, m.index)));
            var mark = document.createElement('mark'); mark.className = 'hl'; mark.textContent = m[0]; frag.appendChild(mark);
            last = m.index + m[0].length; if (m[0].length === 0) re.lastIndex++;
        }
        if (last < text.length) frag.appendChild(document.createTextNode(text.slice(last)));
        p.replaceChild(frag, node);
    });
}
function removeHighlights(el){
    el.querySelectorAll('mark.hl').forEach(function(m){
        var parent = m.parentNode; parent.replaceChild(document.createTextNode(m.textContent), m); parent.normalize();
    });
}
document.addEventListener('keydown', function(e){
    if (e.key === '/' && document.activeElement !== searchInput){ e.preventDefault(); searchInput.focus(); window.scrollTo({ top:0, behavior:'smooth' }); }
    if (e.key === 'Escape'){ searchInput.blur(); clearSearchBox(); }
});
</script>
</body>
</html>