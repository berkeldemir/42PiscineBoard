<?php
session_start();
if (session_status() !== PHP_SESSION_ACTIVE) {
    die('Session başlatılamadı!');
}

// Configuration
$CLIENT_ID = 'u-s4t2ud-f670ca775e8d1cf47135dc36afd07231710cefc84c58bf0318d8660d7126cdc5';
$CLIENT_SECRET = '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';
$REDIRECT_URI = 'http://10.11.3.10:5696/callback'; // .php olmadan
$CACHE_FILE = 'avatar_cache.json';
$INPUTS_FILE = 'inputs.txt';

// Helper fonksiyonları
function send_error($message) {
    echo '<!DOCTYPE html><html><body>';
    echo '<script>alert("' . addslashes($message) . '"); window.history.back();</script>';
    echo '</body></html>';
    exit;
}

function get_api_token() {
    global $CLIENT_ID, $CLIENT_SECRET;
    
    $ch = curl_init('https://api.intra.42.fr/oauth/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'client_credentials',
            'client_id' => $CLIENT_ID,
            'client_secret' => $CLIENT_SECRET
        ])
    ]);
    
    $response = curl_exec($ch);
    $data = json_decode($response, true);
    return $data['access_token'] ?? null;
}

function get_avatar_url($username) {
    global $CACHE_FILE;
    static $cache = null;
    
    if ($cache === null) {
        if (file_exists($CACHE_FILE)) {
            $json = file_get_contents($CACHE_FILE);
            $cache = json_decode($json, true) ?: [];
        } else {
            $cache = [];
        }
    }

    if (!array_key_exists($username, $cache)) {
        $token = get_api_token();
        $ch = curl_init('https://api.intra.42.fr/v2/users/' . rawurlencode($username));
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ["Authorization: Bearer $token"],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FAILONERROR => true
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        $cache[$username] = ($httpCode === 200) 
            ? (json_decode($response, true)['image']['versions']['small'] ?? null)
            : null;

        file_put_contents($CACHE_FILE, json_encode($cache), LOCK_EX);
    }

    return $cache[$username];
}

// OAuth Callback Handler
if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/callback') {
    if (!isset($_GET['code'])) {
        send_error('Authorization code bulunamadı.');
    }

    // State kontrolü
    if (empty($_SESSION['oauth_state']) || $_GET['state'] !== $_SESSION['oauth_state']) {
        send_error('Geçersiz state parametresi.');
    }
    unset($_SESSION['oauth_state']);

    // Token exchange
    $ch = curl_init('https://api.intra.42.fr/oauth/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'authorization_code',
            'client_id' => $CLIENT_ID,
            'client_secret' => $CLIENT_SECRET,
            'code' => $_GET['code'],
            'redirect_uri' => $REDIRECT_URI
        ])
    ]);
    $token = json_decode(curl_exec($ch), true)['access_token'] ?? null;

    if (!$token) {
        send_error('Token alınamadı.');
    }

    // Kullanıcı bilgilerini al
    $ch = curl_init('https://api.intra.42.fr/v2/me');
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $token"],
        CURLOPT_RETURNTRANSFER => true
    ]);
    $user = json_decode(curl_exec($ch), true);

    if (!$user) {
        send_error('Kullanıcı bilgileri alınamadı.');
    }

    // Session'a kaydet
    $_SESSION['user'] = [
        'username' => $user['login'],
        'avatar' => $user['image']['versions']['small'] ?? null
    ];

    header('Location: /');
    exit;
}

function exchange_code_for_token($code) {
    global $CLIENT_ID, $CLIENT_SECRET, $REDIRECT_URI;

    $ch = curl_init('https://api.intra.42.fr/oauth/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'authorization_code',
            'client_id' => $CLIENT_ID,
            'client_secret' => $CLIENT_SECRET,
            'code' => $code,
            'redirect_uri' => $REDIRECT_URI
        ]),
        CURLOPT_SSL_VERIFYPEER => false, // Geçici çözüm
        CURLOPT_VERBOSE => true // Hata ayıklama
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    // Hata loglama
    error_log("Token Response: " . print_r($response, true));
    error_log("cURL Error: " . $error);
    error_log("HTTP Code: " . $httpCode);

    $data = json_decode($response, true);
    return $data['access_token'] ?? null;
}

function get_user_info($token) {
    $ch = curl_init('https://api.intra.42.fr/v2/me');
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $token"],
        CURLOPT_RETURNTRANSFER => true
    ]);
    return json_decode(curl_exec($ch), true);
}

// Formu OAuth ile uyumlu hale getirme
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_SESSION['user'])) {
        send_error('Lütfen önce giriş yapın.');
    }
    
    $username = $_SESSION['user']['username'];
    $message = trim($_POST['message'] ?? '');
    
if ($username === '' || $message === '') {
        send_error('Username and message cannot be empty.');
    }

    // Check duplicate
    $existing_usernames = [];
    if (file_exists($INPUTS_FILE)) {
        foreach (file($INPUTS_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
	$parts = explode(' ||| ', $line);
            if (count($parts) >= 1) {
                $existing_usernames[] = $parts[0];
            }
        }
    }

    if (in_array($username, $existing_usernames)) {
        send_error('This user has already posted a message.');
    }

    // Validate Intra user
    $avatar = get_avatar_url($username);
    if ($avatar === null) {
        send_error('Username not found on intra.42.fr.');
    }

    // Append to file
    $ip = $_SERVER['REMOTE_ADDR'];
    file_put_contents($INPUTS_FILE, "$username ||| $message ||| $ip\n", FILE_APPEND | LOCK_EX);

    // Reload to avoid resubmission
    header('Location: /');
    exit();
}

// Load messages for display
$messages = [];
if (file_exists($INPUTS_FILE)) {
    foreach (file($INPUTS_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $parts = explode(' ||| ', $line, 3);
        if (count($parts) >= 2) {
            $messages[] = ['username' => $parts[0], 'message' => $parts[1]];
        }
    }
}
shuffle($messages);
?>

<!DOCTYPE html>
<html>
<head>
    <title>42 Message Board</title>
    <style>
	* {
		font-size: 28px;
		text-decoration: none;
	 }
	a {
		color: #111;
	}
        :root {
            --header-height: 120px;
        }

	body {
	    transition: opacity 0.8s ease-in;
	    margin: 0;
	    margin-top: 15px;
	    background: #111;
            font-family: 'Segoe UI', system-ui, sans-serif;
	}


.header {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        height: var(--header-height);
        background: #181818ee;
        backdrop-filter: blur(10px);
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 0 20px;
        box-shadow: 0 2px 15px rgba(0,0,0,0.2);
        z-index: 1000;
    }

    /* Modern Login Button */
    .login-button {
        background: #2dc4a6;
        color: white;
        padding: 12px 24px;
        border-radius: 8px;
        text-decoration: none;
        font-weight: 600;
        transition: all 0.2s ease;
        display: flex;
        align-items: center;
        gap: 10px;
        border: 2px solid rgba(255,255,255,0.1);
        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    }

    .login-button:hover {
        background: #24a58e;
        transform: translateY(-2px);
        box-shadow: 0 6px 12px rgba(0,0,0,0.15);
    }

    .login-button:active {
        transform: translateY(0);
    }

    /* Form Positioning */
    .header form {
        width: 65%;
        display: grid;
        grid-template-columns: 1fr auto;
        gap: 55px;
        margin: 0;
    }

    @media (max-width: 768px) {
        .header {
            height: 100px;
            padding: 0 10px;
        }
        
        .header form {
            grid-template-columns: 1fr;
        }
        
        .login-button {
            padding: 10px 25px;
            font-size: 0.9em;
        }
    }

        /* Messages Grid */
        .messages-container {
            padding: calc(var(--header-height) + 15px) 2% 10px;
	    display: flex;
	    flex-wrap: wrap;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            grid-auto-rows: auto;
            gap: 30px;
            width: 100%;
            box-sizing: border-box;
	}

	.username {margin-top: auto;}

        /* Message Bubble */
	.bubble {
	    flex: 1 1 300px;
            position: relative;
            padding: 25px;
            border-radius: 20px;
            background: linear-gradient(145deg, rgba(255,255,255,0.95), rgba(245,245,245,0.95));
            box-shadow: 0 5px 25px rgba(0,0,0,0.08);
            transition: transform 0.2s ease;
            min-height: 120px;
            display: flex;
            flex-direction: column;

	opacity: 0;
	transform: translateY(20px);
	animation: fadeInBubble 0.6s ease forwards;

	}

        .bubble:hover {
            transform: translateY(-3px);
        }

        /* Bubble Content: wrap long text without altering layout */
	.bubble-content {
    		overflow-wrap: break-word;
    		word-break: normal;
		padding-bottom: 100px;
	}

        /* Profile Images */
        .profile-img {
            position: absolute;
            right: 20px;
            bottom: 20px;
            width: 80px;
            height: 80px;
            border-radius: 18px;
            border: 3px solid white;
            box-shadow: 0 5px 15px rgba(0,0,0,0.15);
            object-fit: cover;
            transition: transform 0.3s ease;
        }

        .profile-img:hover {
            transform: scale(1.50);
        }

        /* Form Elements */
        input, button {
            border: none;
            border-radius: 12px;
            padding: 15px;
            font-size: 1em;
            transition: all 0.2s ease;
	}

	.user-username {
		color: #888;
		padding: 30px;
	}
	
        input {
            background: rgba(2,2,2,0.9);
            color: #ccc;
	    font-size: 24px;
	    width: 100%;
	    border: 1px solid rgba(203,213,225,0.4);
        }

        input:focus {
            outline: none;
            border-color: #818cf8;
            box-shadow: 0 0 0 3px rgba(129,140,248,0.2);
        }

        button {
            background: #2dc4a6;
            color: white;
            font-weight: 600;
            cursor: pointer;
            min-width: 150px;
        }

        button:hover {
            background: #4f46e5;
            transform: translateY(-1px);
	}

.centered-container {
  display: flex;
  justify-content: center; /* Horizontal center */
  align-items: center;     /* Vertical center */
  height: 100%;           /* Or whatever height you want */
}

.centered-container img {
  max-width: 100%;
  height: auto;
}

        @media (max-width: 768px) {
            .header form {
                grid-template-columns: 1fr;
                padding: 20px;
            }

            .header {
                height: 120px;
            }

            .header:hover {
                height: 240px;
            }
	}

	@keyframes fadeInBubble {
   	 to {
        opacity: 1;
        transform: translateY(0);
    	}

}
    </style>
</head>
<script>
document.addEventListener("DOMContentLoaded", () => {
    const bubbles = document.querySelectorAll(".bubble");
    bubbles.forEach((bubble, index) => {
        bubble.style.animationDelay = `${index * 0.1}s`;
    });
});
</script>
<body>
<header class="header">
    <?php if (!isset($_SESSION['user'])): ?>
        <?php
            $state = bin2hex(random_bytes(16));
            $_SESSION['oauth_state'] = $state;
            $authUrl = "https://api.intra.42.fr/oauth/authorize?" . http_build_query([
                'client_id' => $CLIENT_ID,
                'redirect_uri' => $REDIRECT_URI,
                'response_type' => 'code',
                'scope' => 'public',
                'state' => $state
            ]);
        ?>
        <a href="<?= $authUrl ?>" class="login-button">Continue with 42</a>
    <?php else: ?>
	<form method="post">
	<input type="text" name="message" placeholder="<?= htmlspecialchars($_SESSION['user']['username']) ?>, leave a (1) message to piscine!" required>
            <button type="submit">Post it!</button>
	</form>
    <?php endif; ?>
</header>    
<div class="messages-container">

	<div class="bubble" style="background: #fff">
		<div class="bubble-content centered-container" style="padding:0px; margin:0px; background-color:#fff;">
			<img src="Nisan2025.png" alt="Nisan2025">
		</div>
	</div>
	<?php foreach ($messages as $msg):
            $hue = rand(0, 360);
	?>
        <div class="bubble" style="background: linear-gradient(145deg, hsl(<?= $hue ?> 90% 96%), hsl(<?= $hue ?> 80% 92%))">
            <div class="bubble-content">
                <strong class="msg"><?= htmlspecialchars($msg['message']) ?></strong>
	    </div>
	    <a class="username" href="https://profile.intra.42.fr/users/<?= htmlspecialchars($msg['username'])?>"><?= htmlspecialchars($msg['username']) ?></a>
	    <a href="https://profile.intra.42.fr/users/<?= htmlspecialchars($msg['username'])?>">
	<img class="profile-img"
                 src="<?= htmlspecialchars(get_avatar_url($msg['username'])) ?>"
                 alt="<?= htmlspecialchars($msg['username']) ?>"
                 onerror="this.style.display='none'">
	</a>
	</div>
        <?php endforeach; ?>
    </div>
    <div style="
	position: fixed;
    bottom: 10px;
    left: 0;
    right: 0;
    text-align: center;
    font-size: 12px;
    color: #666;
    font-family: inherit;">
        created by beldemir
    </div>
</body>
</html>
