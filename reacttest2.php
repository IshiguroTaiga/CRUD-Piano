<?php
session_start();

$mode = $_GET['mode'] ?? 'menu';
$title = match($mode) {
  'play'   => "Play Mode",
  'free'   => "Free Play",
  'login'  => "Login",
  'signup' => "Sign Up",
  default  => "Simple CRUD + Web Piano"
};

// Database credentials
$db_host = "sql302.infinityfree.com";
$db_user = "if0_38869516";
$db_pass = "BryNyMic";
$db_name = "if0_38869516_dbplayers";

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

// Handle signup
$signup_error = "";
if ($_SERVER["REQUEST_METHOD"] === "POST" && $mode === "signup") {
  $username = $_POST["username"];
  $raw_password = $_POST["password"];
  $password = password_hash($raw_password, PASSWORD_DEFAULT);  

  // Ensure username is unique
  $stmt = $conn->prepare("SELECT username FROM players WHERE username=? LIMIT 1");
  $stmt->bind_param("s", $username);
  $stmt->execute();
  $result = $stmt->get_result();

  if ($result->num_rows === 0) {
    $stmt = $conn->prepare("INSERT INTO players (username, password, raw_password) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $username, $password, $raw_password);
    
    if ($stmt->execute()) {
      header("Location: index.php?mode=login");
      exit;
    } else {
      $signup_error = "Error creating account. Please try again.";
    }
  } else {
    $signup_error = "Username already taken. Please choose another.";
  }
}

// Handle login
$login_error = "";
if ($_SERVER["REQUEST_METHOD"] === "POST" && $mode === "login") {
  $username = $_POST["username"];
  $password = $_POST["password"];
  $stmt = $conn->prepare("SELECT password FROM players WHERE BINARY username=? LIMIT 1");
  $stmt->bind_param("s", $username);
  $stmt->execute();
  $result = $stmt->get_result();

  if ($result->num_rows === 1) {
    $row = $result->fetch_assoc();
    if (password_verify($password, $row["password"])) {
      $_SESSION['username'] = $username;
      header("Location: index.php?mode=menu");
      exit;
    } else {
      $login_error = "Invalid login. Please try again.";
    }
  } else {
    $login_error = "Invalid login. Please try again.";
  }
}

// Handle logout
if (isset($_GET['logout'])) {
  session_destroy();
  header("Location: index.php?mode=menu");
  exit;
}

// Handle Delete
if ($mode === 'delete' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $stmt = $conn->prepare("DELETE FROM players WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    header("Location: index.php?mode=accounts");
    exit;
  }
  
// Handle Update form display
if ($mode === 'update' && isset($_GET['id'])) {
  $update_id = (int)$_GET['id'];
  $result = $conn->query("SELECT id, username, raw_password FROM players WHERE id=$update_id");
  $update_user = $result->fetch_assoc();
}

// Handle Update form submit
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["update_user"])) {
  $update_id = (int)$_POST["id"];
  $new_username = $_POST["username"];
  $new_password = $_POST["password"];
  $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

  $stmt = $conn->prepare("UPDATE players SET username=?, password=?, raw_password=? WHERE id=?");
  $stmt->bind_param("sssi", $new_username, $hashed_password, $new_password, $update_id);
  $stmt->execute();

  header("Location: index.php?mode=accounts");
  exit;
}

// Handle Free Accounts + free play display
if ($mode === 'free') {
  $result = $conn->query("SELECT username, raw_password FROM players ORDER BY created_at DESC LIMIT 5");
  $free_accounts = $result->fetch_all(MYSQLI_ASSOC);
} else {
  $free_accounts = [];
}

// Prepare data for React
$react_data = [
  'mode' => $mode,
  'title' => $title,
  'username' => $_SESSION['username'] ?? null,
  'signup_error' => $signup_error,
  'login_error' => $login_error,
  'free_accounts' => $free_accounts,
  'update_user' => $update_user ?? null
];

if ($mode === 'accounts' || $mode === 'update') {
  $result = $conn->query("SELECT id, username, raw_password FROM players ORDER BY created_at DESC");
  $react_data['accounts'] = $result->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title><?= htmlspecialchars($title) ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <!-- Add Bootstrap CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <!-- Keep Tone.js -->
  <script src="https://cdn.jsdelivr.net/npm/tone@14.8.30"></script>
  <!-- React dependencies -->
  <script src="https://unpkg.com/react@18/umd/react.development.js"></script>
  <script src="https://unpkg.com/react-dom@18/umd/react-dom.development.js"></script>
  <script src="https://unpkg.com/@babel/standalone/babel.min.js"></script>
  <style>
    /* Custom color palette - Raiden (purple) x Shorekeeper (blue) kasi why not */
    :root {
        --game-purple:rgb(126, 46, 238);
        --game-purple-dark:rgb(119, 0, 255);
        --shorekeeper-blue:rgba(13, 193, 230, 0.8);
        --shorekeeper-blue-dark: #0077b6;
        --dark-bg:rgba(22, 22, 36, 0.76);
        --darker-bg:rgb(7, 7, 15);
        --text-light: #e6f7ff;
    }

    /* Base styles */
    body {
        background: linear-gradient(135deg, var(--darker-bg), var(--dark-bg));
        color: var(--text-light);
        min-height: 100vh;
    }

    /* Piano-specific styles - Updated for mobile responsiveness */
    .piano-container {
      width: 100%;
      overflow-x: auto;
      padding-bottom: 20px;
      -webkit-overflow-scrolling: touch;
    }
    
    .piano {
      position: relative;
      display: inline-flex;
      min-width: 100%;
      height: 180px;
      touch-action: manipulation;
    }
    
    .white-keys {
      display: flex;
      position: relative;
      flex: 1;
      min-width: 100%;
      height: 100%;
    }
    
    .key {
      position: relative;
      flex: 1;
      height: 100%;
      background: white;
      border: 1px solid #ccc;
      -webkit-user-select: none;
      user-select: none;
    }
    
    .key.black {
      position: absolute;
      width: 60%;
      height: 60%;
      background: black;
      z-index: 2;
      top: 0;
    }
    
    /* Black key positioning */
    .key[data-note="C#4"] { left: 6.5%; }
    .key[data-note="D#4"] { left: 18.5%; }
    .key[data-note="F#4"] { left: 42.5%; }
    .key[data-note="G#4"] { left: 54.5%; }
    .key[data-note="A#4"] { left: 66.5%; }
    .key[data-note="C#5"] { left: 90.5%; }
    .key[data-note="D#5"] { left: 102.5%; }
    
    .label {
      position: absolute;
      bottom: 5px;
      left: 0;
      right: 0;
      text-align: center;
      font-size: 10px;
      color: #111;
    }
    
    .key.black .label {
      color: #fff;
    }
    
    .key.active {
      box-shadow: 0 0 10px var(--shorekeeper-blue);
      filter: brightness(1.1);
    }
    
    /* Responsive adjustments */
    @media (max-width: 576px) {
      .piano {
        height: 150px;
      }
      
      .key.black {
        width: 50%;
        height: 55%;
      }
      
      .label {
        font-size: 8px;
      }
    }
    
    /* Bootstrap overrides */
    .alert-warning {
        background-color: var(--game-purple-dark);
        color: white;
        border-color: var(--shorekeeper-blue);
    }
    
    .navbar-dark.bg-dark {
        background-color: var(--dark-bg) !important;
        border-bottom: 1px solid var(--game-purple);
    }
    
    .card {
        background-color: rgba(15, 15, 34, 0.8) !important;
        border: 1px solid var(--game-purple);
    }
    
    .btn-primary {
        background-color: var(--game-purple);
        border-color: var(--game-purple-dark);
    }
    
    .btn-primary:hover {
        background-color: var(--game-purple-dark);
        border-color: var(--game-purple);
    }
    
    .btn-success {
        background-color: var(--shorekeeper-blue);
        border-color: var(--shorekeeper-blue-dark);
    }
    
    .btn-success:hover {
        background-color: var(--shorekeeper-blue-dark);
        border-color: var(--shorekeeper-blue);
    }
    
    .btn-info {
        background-color: var(--shorekeeper-blue-dark);
        border-color: var(--shorekeeper-blue);
    }
    
    .btn-secondary {
        background-color: var(--dark-bg);
        border-color: var(--game-purple);
    }
    
    .table-dark {
        background-color: var(--darker-bg);
        color: var(--text-light);
    }
    
    .table-dark th,
    .table-dark td,
    .table-dark thead th {
        border-color: var(--game-purple);
    }
    
    .table-striped tbody tr:nth-of-type(odd) {
        background-color: rgba(106, 0, 255, 0.1);
    }
    
    .list-group-item {
        background-color: rgba(10, 10, 26, 0.5);
        color: var(--text-light);
        border-color: var(--game-purple);
    }
    
    .badge.bg-primary {
        background-color: var(--shorekeeper-blue) !important;
    }
    
    .badge.bg-secondary {
        background-color: var(--game-purple) !important;
    }
    
    .badge.bg-success {
        background-color: var(--shorekeeper-blue-dark) !important;
    }
    
    /* Modal styling */
    .modal-content {
        background: var(--dark-bg);
        box-shadow: 0 0 15px var(--game-purple);
        color: var(--text-light);
        border: 1px solid var(--shorekeeper-blue);
    }
    
    .modal-header {
        border-bottom: 1px solid var(--game-purple);
    }
    
    .modal-footer {
        border-top: 1px solid var(--game-purple);
    }
    
    .btn-close {
        filter: invert(1);
    }
    
    /* Form controls */
    .form-control {
        background-color: rgba(10, 10, 26, 0.8);
        border: 1px solid var(--game-purple);
        color: var(--text-light);
    }
    
    .form-control:focus {
        background-color: rgba(10, 10, 26, 0.9);
        border-color: var(--shorekeeper-blue);
        color: var(--text-light);
        box-shadow: 0 0 0 0.25rem rgba(0, 180, 216, 0.25);
    }
    
    /* Alert customization */
    .alert-danger {
        background-color: rgba(255, 0, 0, 0.2);
        border-color: #ff0000;
        color: #ff9999;
    }
    
    .alert-info {
        background-color: rgba(0, 180, 216, 0.2);
        border-color: var(--shorekeeper-blue);
        color: var(--text-light);
    }
    
    /* Links */
    a {
        color: var(--shorekeeper-blue);
    }
    
    a:hover {
        color: var(--shorekeeper-blue-dark);
    }
    
    .alert-link {
        color: var(--shorekeeper-blue) !important;
    }
    
    /* Text colors */
    .text-muted {
        color: #a0a0c0 !important;
    }
  </style>
</head>
<body>
  <div id="root"></div>

  <script type="text/babel">
    // Pass PHP data to React
    const appData = <?= json_encode($react_data) ?>;
    
    // Songs data
    const songs = [
      { title: "Twinkle Twinkle", notes: "a a g g h h g f f d d s s a g g f f d d s g g f f d d s a a g g h h g f f d d s s a" },
      { title: "Happy Birthday? ig", notes: "a a k a ; l a a k a p ; a a p ; l ; k" },
      { title: "Mary Had a Little Lamb", notes: "d s a s d d d s s s d f f d s a s d d d d s s d s a" },
      { title: "W/W t1", notes: "s s d f f d s a s d d a a s s d f f d s a s d a s" },
      { title: "Jingle Bells", notes: "s s s s s s s d a s f g s s s s s s s d a s g f" },
      { title: "W/W t2", notes: "a a a d d d f f f d d d a a a d d d f f f d d d" },
      { title: "W/B t1", notes: "e d e d e j d k h" },
      { title: "W/B t2", notes: "a w a w s e s e d t d t f y f y g u g u" },
      { title: "W/B t3", notes: "d d o d l k j o p l" },
      { title: "W/B t4", notes: "a a d d f f g e t y u" },
      { title: "W/B t5", notes: "d d d g d a d d g d a d f d d d f d d d f" },
      { title: "W/B t6", notes: "a w a w a w s e s e s e d t d t d t f y f y f y" }
    ];

    const notes = [
      { note: 'C4', key: 'a' }, { note: 'C#4', key: 'w', black: true },
      { note: 'D4', key: 's' }, { note: 'D#4', key: 'e', black: true },
      { note: 'E4', key: 'd' },
      { note: 'F4', key: 'f' }, { note: 'F#4', key: 't', black: true },
      { note: 'G4', key: 'g' }, { note: 'G#4', key: 'y', black: true },
      { note: 'A4', key: 'h' }, { note: 'A#4', key: 'u', black: true },
      { note: 'B4', key: 'j' },
      { note: 'C5', key: 'k' }, { note: 'C#5', key: 'o', black: true },
      { note: 'D5', key: 'l' }, { note: 'D#5', key: 'p', black: true },
      { note: 'E5', key: ';' }
    ];

    // Piano component
    function Piano({ mode, onNotePlay }) {
      const keyMap = React.useMemo(() => {
        const map = {};
        notes.forEach(note => {
          map[note.key] = note.note;
        });
        return map;
      }, []);

      const [activeKeys, setActiveKeys] = React.useState(new Set());

      const handleKeyDown = React.useCallback((e) => {
        const key = e.key;
        if (!keyMap[key] || activeKeys.has(key)) return;
        
        setActiveKeys(prev => {
          const newSet = new Set(prev);
          newSet.add(key);
          return newSet;
        });

        const note = keyMap[key];
        if (note) {
          onNotePlay(note, key);
        }
      }, [keyMap, activeKeys, onNotePlay]);

      const handleKeyUp = React.useCallback((e) => {
        const key = e.key;
        if (activeKeys.has(key)) {
          setActiveKeys(prev => {
            const newSet = new Set(prev);
            newSet.delete(key);
            return newSet;
          });
          
          const note = keyMap[key];
          if (note) {
            synth.triggerRelease();
          }
        }
      }, [keyMap, activeKeys]);

      React.useEffect(() => {
        window.addEventListener('keydown', handleKeyDown);
        window.addEventListener('keyup', handleKeyUp);
        return () => {
          window.removeEventListener('keydown', handleKeyDown);
          window.removeEventListener('keyup', handleKeyUp);
        };
      }, [handleKeyDown, handleKeyUp]);

      const handleMouseDown = (note) => {
        synth.triggerAttack(note);
        onNotePlay(note);
      };

      const handleMouseUp = (note) => {
        synth.triggerRelease();
      };

      return (
        <div className="piano-container">
          <div className="piano">
            <div className="white-keys">
              {notes.filter(n => !n.black).map(note => (
                <div 
                  key={note.note}
                  className={`key ${activeKeys.has(note.key) ? 'active' : ''}`}
                  data-key={note.key}
                  data-note={note.note}
                  onMouseDown={() => handleMouseDown(note.note)}
                  onMouseUp={() => handleMouseUp(note.note)}
                  onMouseLeave={() => handleMouseUp(note.note)}
                  onTouchStart={(e) => {
                    e.preventDefault();
                    handleMouseDown(note.note);
                  }}
                  onTouchEnd={() => handleMouseUp(note.note)}
                >
                  <span className="label">{note.key.toUpperCase()}</span>
                </div>
              ))}
            </div>
            <div className="black-keys">
              {notes.filter(n => n.black).map(note => (
                <div 
                  key={note.note}
                  className={`key black ${activeKeys.has(note.key) ? 'active' : ''}`}
                  data-key={note.key}
                  data-note={note.note}
                  onMouseDown={() => handleMouseDown(note.note)}
                  onMouseUp={() => handleMouseUp(note.note)}
                  onMouseLeave={() => handleMouseUp(note.note)}
                  onTouchStart={(e) => {
                    e.preventDefault();
                    handleMouseDown(note.note);
                  }}
                  onTouchEnd={() => handleMouseUp(note.note)}
                >
                  <span className="label">{note.key.toUpperCase()}</span>
                </div>
              ))}
            </div>
          </div>
        </div>
      );
    }

    // PlayMode component
    function PlayMode() {
      const [currentSongIndex, setCurrentSongIndex] = React.useState(-1);
      const [completedSongs, setCompletedSongs] = React.useState(new Set());
      const [currentStep, setCurrentStep] = React.useState(0);
      const [songNotes, setSongNotes] = React.useState([]);
      const [showCompletionModal, setShowCompletionModal] = React.useState(false);
      const [showErrorModal, setShowErrorModal] = React.useState(false);

      const pickRandomSong = React.useCallback(() => {
        const remaining = songs.map((_, i) => i).filter(i => !completedSongs.has(i));
        if (remaining.length === 0) {
          setCompletedSongs(new Set());
          return pickRandomSong();
        }
        return remaining[Math.floor(Math.random() * remaining.length)];
      }, [completedSongs]);

      const loadSong = React.useCallback((idx) => {
        setCurrentSongIndex(idx);
        setSongNotes(songs[idx].notes.split(' '));
        setCurrentStep(0);
      }, []);

      React.useEffect(() => {
        loadSong(pickRandomSong());
      }, [loadSong, pickRandomSong]);

      const handleNotePlay = React.useCallback((note, key) => {
        synth.triggerAttack(note);
        
        const expected = songNotes[currentStep];
        const playedKey = key || notes.find(n => n.note === note)?.key;
        
        if (playedKey === expected) {
          const newStep = currentStep + 1;
          setCurrentStep(newStep);
          
          if (newStep === songNotes.length) {
            setShowCompletionModal(true);
          }
        } else {
          setShowErrorModal(true);
        }
      }, [currentStep, songNotes]);

      const updateProgress = React.useCallback(() => {
        return songNotes.map((k, i) => (
          <span 
            key={`${i}-${k}`}
            className={`badge rounded-pill mx-1 ${i < currentStep ? 'bg-success' : 'bg-secondary'}`}
          >
            {k.toUpperCase()}
          </span>
        ));
      }, [songNotes, currentStep]);

      const handleReplay = () => {
        loadSong(currentSongIndex);
        setShowCompletionModal(false);
      };

      const handleNext = () => {
        const newCompleted = new Set(completedSongs);
        newCompleted.add(currentSongIndex);
        setCompletedSongs(newCompleted);
        loadSong(pickRandomSong());
        setShowCompletionModal(false);
      };

      const handleRetry = () => {
        loadSong(currentSongIndex);
        setShowErrorModal(false);
      };

      const handleNextAfterError = () => {
        loadSong(pickRandomSong());
        setShowErrorModal(false);
      };

      return (
        <>
          <h4 className="text-center mb-3">Now Playing: {songs[currentSongIndex]?.title || 'Loading...'}</h4>
          <p className="text-center mb-4">Press the correct keys in sequence!</p>
          
          <Piano mode="play" onNotePlay={handleNotePlay} />
          
          <div className="d-flex flex-wrap justify-content-center gap-2 my-4">
            {updateProgress()}
          </div>
          
          <div className="text-center mt-4">
            <a href="?mode=menu" className="btn btn-secondary">← Back to Menu</a>
          </div>

          {/* Completion Modal */}
          <div className={`modal fade ${showCompletionModal ? 'show' : ''}`} style={{ display: showCompletionModal ? 'block' : 'none' }}>
            <div className="modal-dialog">
              <div className="modal-content">
                <div className="modal-header">
                  <h5 className="modal-title">🎉 You completed the song!</h5>
                  <button type="button" className="btn-close" onClick={() => setShowCompletionModal(false)}></button>
                </div>
                <div className="modal-body">
                  <p>Great job! Would you like to try again or move to the next song?</p>
                </div>
                <div className="modal-footer">
                  <button className="btn btn-primary" onClick={handleReplay}>Retry</button>
                  <button className="btn btn-success" onClick={handleNext}>Next</button>
                  <a href="?mode=menu" className="btn btn-secondary">Exit</a>
                </div>
              </div>
            </div>
          </div>
          {showCompletionModal && <div className="modal-backdrop fade show"></div>}

          {/* Error Modal */}
          <div className={`modal fade ${showErrorModal ? 'show' : ''}`} style={{ display: showErrorModal ? 'block' : 'none' }}>
            <div className="modal-dialog">
              <div className="modal-content">
                <div className="modal-header">
                  <h5 className="modal-title">❌ Wrong note!</h5>
                  <button type="button" className="btn-close" onClick={() => setShowErrorModal(false)}></button>
                </div>
                <div className="modal-body">
                  <p>Try again from the start or move to the next song.</p>
                </div>
                <div className="modal-footer">
                  <button className="btn btn-primary" onClick={handleRetry}>Retry</button>
                  <button className="btn btn-success" onClick={handleNextAfterError}>Next</button>
                  <a href="?mode=menu" className="btn btn-secondary">Exit</a>
                </div>
              </div>
            </div>
          </div>
          {showErrorModal && <div className="modal-backdrop fade show"></div>}
        </>
      );
    }

    // FreePlay component
    function FreePlay({ freeAccounts }) {
      const [activeKeys, setActiveKeys] = React.useState(new Set());
      const [showAccounts, setShowAccounts] = React.useState(false);

      const handleNotePlay = (note) => {
        synth.triggerAttack(note);
      };

      return (
        <>
          <div className="alert alert-warning text-center mb-0 rounded-0">
            <button 
              className="text-decoration-none text-dark fw-bold border-0 bg-transparent w-100"
              onClick={() => setShowAccounts(!showAccounts)}
            >
              🔐 Forgotten your password? Want to log in to someone else's account? (Click to view)
            </button>
          </div>

          {showAccounts && (
            <div id="free-accounts" className="card bg-secondary mb-4">
              <div className="card-body">
                <h3 className="card-title">Registered Accounts</h3>
                <p className="card-text">Accounts registered if you've forgotten your password:</p>
                <ul className="list-group">
                  {freeAccounts.map((account, index) => (
                    <li key={index} className="list-group-item d-flex justify-content-between align-items-center">
                      <strong>{account.username}</strong>
                      <span className="badge bg-primary rounded-pill">{account.raw_password}</span>
                    </li>
                  ))}
                </ul>
              </div>
            </div>
          )}

          <Piano mode="free" onNotePlay={handleNotePlay} />
          
          <div className="text-center mt-4">
            <a href="?mode=menu" className="btn btn-secondary">← Back to Menu</a>
          </div>
        </>
      );
    }

    // LoginForm component
    function LoginForm({ error, mode }) {
      return (
        <form method="post" className="mb-4">
          <div className="mb-3">
            <input type="text" name="username" className="form-control" placeholder="Username" required />
          </div>
          <div className="mb-3">
            <input type="password" name="password" className="form-control" placeholder="Password" required />
          </div>
          <button type="submit" className="btn btn-primary w-100">Login</button>
          {error && <div className="alert alert-danger mt-3">{error}</div>}
          <div className="text-center mt-3">
            <a href="?mode=signup">Create an account</a>
          </div>
        </form>
      );
    }

    // SignupForm component
    function SignupForm({ error, mode }) {
      return (
        <form method="post" className="mb-4">
          <div className="mb-3">
            <input type="text" name="username" className="form-control" placeholder="Username" required />
          </div>
          <div className="mb-3">
            <input type="password" name="password" className="form-control" placeholder="Password" required />
          </div>
          <button type="submit" className="btn btn-primary w-100">Sign Up</button>
          {error && <div className="alert alert-danger mt-3">{error}</div>}
          <div className="text-center mt-3">
            <a href="?mode=login">Already have an account? Login</a>
          </div>
        </form>
      );
    }

    // Accounts component
    function Accounts({ accounts, updateUser }) {
      if (appData.mode === 'update' && updateUser) {
        return (
          <>
            <h3 className="mb-4">Update Account</h3>
            <form method="post">
              <input type="hidden" name="id" value={updateUser.id} />
              <div className="mb-3">
                <input 
                  type="text" 
                  name="username" 
                  className="form-control" 
                  defaultValue={updateUser.username} 
                  required 
                />
              </div>
              <div className="mb-3">
                <input 
                  type="password" 
                  name="password" 
                  className="form-control" 
                  placeholder="New Password" 
                  required 
                />
              </div>
              <button type="submit" name="update_user" className="btn btn-primary">Update</button>
              <a href="?mode=accounts" className="btn btn-secondary">Cancel</a>
            </form>
          </>
        );
      } else {
        return (
          <>
            <h3 className="mb-4">👥 Registered Accounts</h3>
            <div className="table-responsive">
              <table className="table table-dark table-striped">
                <thead>
                  <tr>
                    <th>Username</th>
                    <th>Password</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {accounts.map(account => (
                    <tr key={account.id}>
                      <td>{account.username}</td>
                      <td>{account.raw_password}</td>
                      <td>
                        <a href={`?mode=update&id=${account.id}`} className="btn btn-sm btn-outline-info">Update</a>
                        <a 
                          href={`?mode=delete&id=${account.id}`}
                          className="btn btn-sm btn-outline-danger"
                          onClick={(e) => {
                            if (!confirm('Are you sure you want to delete this account?')) {
                              e.preventDefault();
                            }
                          }}
                        >
                          Delete
                        </a>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <a href="?mode=menu" className="btn btn-secondary mt-3">← Back to Menu</a>
          </>
        );
      }
    }

    // Menu component
    function Menu({ username }) {
      return (
        <>
          <p className="text-muted mb-4">Not meant for real piano performance—just made for a project and for fun!</p>
          {!username ? (
            <div className="alert alert-info">
              Please <a href="?mode=login" className="alert-link">log in</a> to play.
            </div>
          ) : (
            <div className="d-grid gap-3">
              <button 
                onClick={() => window.location.href='?mode=play'}
                className="btn btn-primary btn-lg py-3"
              >
                Play Mode
              </button>
              <button 
                onClick={() => window.location.href='?mode=free'}
                className="btn btn-success btn-lg py-3"
              >
                Free Play
              </button>
              <button 
                onClick={() => window.location.href='?mode=accounts'}
                className="btn btn-info btn-lg py-3"
              >
                See Accounts
              </button>
            </div>
          )}
        </>
      );
    }

    // Main App component
    function App() {
      const { mode, title, username, signup_error, login_error, free_accounts, accounts, update_user } = appData;
      
      return (
        <div className="d-flex flex-column min-vh-100">
          {/* Alert bar at top */}
          <div className="alert alert-warning text-center mb-0 rounded-0">
            <a href="?mode=free#free-accounts" className="text-decoration-none text-dark fw-bold">
              🔐 Forgotten your password? Want to log in to someone else's account? (Click to view)
            </a>
          </div>

          {/* Navigation bar */}
          <nav className="navbar navbar-expand-lg navbar-dark bg-dark">
            <div className="container">
              <a className="navbar-brand" href="?mode=menu">Web Piano</a>
              <div className="navbar-text ms-auto">
                {username ? (
                  <>
                    Logged in as <strong>{username}</strong> |
                    <a href="?logout=1" className="text-white"> Logout</a>
                  </>
                ) : (
                  <>
                    <a href="?mode=login" className="text-white">Login</a> | 
                    <a href="?mode=signup" className="text-white">Sign Up</a>
                  </>
                )}
              </div>
            </div>
          </nav>

          <div className="container my-5 flex-grow-1">
            <div className="row justify-content-center">
              <div className="col-lg-8">
                <div className="card bg-dark text-white p-4 shadow">
                  <h2 className="card-title text-center mb-4">{title}</h2>
                  
                  {mode === 'signup' ? (
                    <SignupForm error={signup_error} mode={mode} />
                  ) : mode === 'login' ? (
                    <LoginForm error={login_error} mode={mode} />
                  ) : mode === 'accounts' || mode === 'update' ? (
                    username ? (
                      <Accounts accounts={accounts} updateUser={update_user} />
                    ) : (
                      <div className="alert alert-warning">
                        You must be logged in to view accounts. <a href="?mode=login" className="alert-link">Go to Login</a>
                      </div>
                    )
                  ) : mode === 'menu' ? (
                    <Menu username={username} />
                  ) : mode === 'play' ? (
                    username ? (
                      <PlayMode />
                    ) : (
                      <div className="alert alert-warning">
                        You must be logged in. <a href="?mode=login" className="alert-link">Go to Login</a>
                      </div>
                    )
                  ) : mode === 'free' ? (
                    <FreePlay freeAccounts={free_accounts} />
                  ) : null}
                </div>
              </div>
            </div>
          </div>
        </div>
      );
    }

    // Initialize Tone.js synth
    const synth = new Tone.Synth().toDestination();

    // Render the app
    const root = ReactDOM.createRoot(document.getElementById('root'));
    root.render(<App />);
  </script>
</body>
</html>