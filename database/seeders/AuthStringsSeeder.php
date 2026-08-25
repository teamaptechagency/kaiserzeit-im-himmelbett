<?php

namespace Database\Seeders;

use App\Models\Translation;
use Illuminate\Database\Seeder;

/**
 * Every word on the sign-in, sign-up and two-factor screens, in every
 * language the site offers.
 *
 * These used to be German written into the Blade files with an English line
 * underneath. That worked until somebody asked the obvious question — how
 * does a guest who reads no German switch it round? — which they cannot do
 * against text baked into a template.
 *
 * Here they are rows, so the picker can lead with any of them. They are also
 * editable from Configuration → Translations like everything else, which
 * matters: these are interface terms, and where a wording below is stiff, the
 * person who runs the site can fix it without a deployment.
 *
 * Only the interface is translated here. The site's own copy — the hero line,
 * the flats' descriptions — is the client's writing and is not something to
 * be guessed at in ten languages; it stays in the CMS for a person to do.
 *
 * updateOrCreate, so re-running repairs what is missing without overwriting a
 * wording somebody has since improved.
 */
class AuthStringsSeeder extends Seeder
{
    /** The order the columns come in below. */
    private const ORDER = ['de', 'en', 'fr', 'es', 'it', 'nl', 'pl', 'cs', 'pt', 'ru', 'tr', 'zh'];

    /**
     * key => one value per language, in ORDER.
     *
     * A flat list rather than a nested map: twelve short strings on one line
     * can be read across and checked, and a missing one is visible as a gap
     * rather than hidden in a key nobody looks at.
     */
    private const STRINGS = [
        /* ---------------------------------------------------------- shared */
        'brand_signin' => ['Anmelden', 'Sign in', 'Connexion', 'Iniciar sesión', 'Accedi', 'Aanmelden', 'Zaloguj się', 'Přihlásit se', 'Entrar', 'Вход', 'Giriş', '登录'],
        'brand_signup' => ['Registrieren', 'Sign up', 'Inscription', 'Registrarse', 'Registrati', 'Registreren', 'Rejestracja', 'Registrace', 'Registar', 'Регистрация', 'Kayıt', '注册'],
        'brand_2fa'    => ['Zwei-Faktor', 'Two-factor', 'Double facteur', 'Doble factor', 'Due fattori', 'Twee­staps', 'Dwuskładnikowe', 'Dvoufázové', 'Dois fatores', 'Двухфакторная', 'İki adımlı', '两步验证'],
        'back_to_site' => ['Zurück zur Website', 'Back to the site', 'Retour au site', 'Volver al sitio', 'Torna al sito', 'Terug naar de site', 'Powrót do strony', 'Zpět na web', 'Voltar ao site', 'Вернуться на сайт', 'Siteye dön', '返回网站'],
        'language'     => ['Sprache', 'Language', 'Langue', 'Idioma', 'Lingua', 'Taal', 'Język', 'Jazyk', 'Idioma', 'Язык', 'Dil', '语言'],

        /* --------------------------------------------------------- sign in */
        'signin_title'  => ['Willkommen zurück', 'Welcome back', 'Bon retour', 'Bienvenido de nuevo', 'Bentornato', 'Welkom terug', 'Witamy ponownie', 'Vítejte zpět', 'Bem-vindo de volta', 'С возвращением', 'Tekrar hoş geldiniz', '欢迎回来'],
        'signin_lead'   => [
            'Gäste und Team melden sich hier an — wohin es danach geht, entscheidet Ihr Konto.',
            'Guests and staff sign in here — your account decides where you land.',
            'Clients et équipe se connectent ici — votre compte détermine la suite.',
            'Huéspedes y personal entran aquí — su cuenta decide a dónde llega.',
            'Ospiti e staff accedono qui — è il vostro account a decidere dove arrivate.',
            'Gasten en team melden zich hier aan — uw account bepaalt waar u uitkomt.',
            'Goście i zespół logują się tutaj — to konto decyduje, dokąd trafisz.',
            'Hosté i tým se přihlašují zde — kam se dostanete, určuje váš účet.',
            'Hóspedes e equipa entram aqui — a sua conta decide para onde vai.',
            'Гости и сотрудники входят здесь — куда именно, решает ваша учётная запись.',
            'Misafirler ve ekip buradan giriş yapar — nereye gideceğinizi hesabınız belirler.',
            '客人与团队均由此登录 — 去往何处由您的账户决定。',
        ],
        'identifier'      => ['Benutzername oder E-Mail', 'Username or email', 'Identifiant ou e-mail', 'Usuario o correo', 'Nome utente o e-mail', 'Gebruikersnaam of e-mail', 'Nazwa użytkownika lub e-mail', 'Uživatelské jméno nebo e-mail', 'Utilizador ou e-mail', 'Имя пользователя или e-mail', 'Kullanıcı adı veya e-posta', '用户名或邮箱'],
        'password'        => ['Passwort', 'Password', 'Mot de passe', 'Contraseña', 'Password', 'Wachtwoord', 'Hasło', 'Heslo', 'Palavra-passe', 'Пароль', 'Parola', '密码'],
        'signin_button'   => ['Anmelden', 'Sign in', 'Se connecter', 'Iniciar sesión', 'Accedi', 'Aanmelden', 'Zaloguj się', 'Přihlásit se', 'Entrar', 'Войти', 'Giriş yap', '登录'],
        'no_account'      => ['Noch kein Konto?', 'No account yet?', 'Pas encore de compte ?', '¿Aún no tiene cuenta?', 'Non hai ancora un account?', 'Nog geen account?', 'Nie masz jeszcze konta?', 'Ještě nemáte účet?', 'Ainda não tem conta?', 'Ещё нет аккаунта?', 'Hesabınız yok mu?', '还没有账户？'],
        'no_account_link' => ['Registrieren', 'Sign up', 'S’inscrire', 'Registrarse', 'Registrati', 'Registreren', 'Zarejestruj się', 'Registrovat se', 'Registar', 'Зарегистрироваться', 'Kayıt ol', '注册'],
        'no_admin'        => ['Noch kein Administrator —', 'No administrator yet —', 'Aucun administrateur —', 'Aún sin administrador —', 'Nessun amministratore —', 'Nog geen beheerder —', 'Brak administratora —', 'Zatím žádný správce —', 'Ainda sem administrador —', 'Администратора ещё нет —', 'Henüz yönetici yok —', '尚无管理员 —'],
        'no_admin_link'   => ['Konto anlegen', 'Create the first account', 'Créer le premier compte', 'Crear la primera cuenta', 'Crea il primo account', 'Eerste account aanmaken', 'Utwórz pierwsze konto', 'Vytvořit první účet', 'Criar a primeira conta', 'Создать первую запись', 'İlk hesabı oluştur', '创建首个账户'],

        /* -------------------------------------------------------- passkeys */
        'passkey_button' => ['Fingerabdruck oder Gesicht', 'Fingerprint or face', 'Empreinte ou visage', 'Huella o rostro', 'Impronta o volto', 'Vingerafdruk of gezicht', 'Odcisk palca lub twarz', 'Otisk prstu nebo obličej', 'Impressão digital ou rosto', 'Отпечаток или лицо', 'Parmak izi veya yüz', '指纹或人脸'],
        'passkey_or'     => ['oder mit Passwort', 'or with a password', 'ou avec un mot de passe', 'o con contraseña', 'oppure con password', 'of met een wachtwoord', 'albo hasłem', 'nebo heslem', 'ou com palavra-passe', 'или паролем', 'ya da parolayla', '或使用密码'],

        /* --------------------------------------------------------- sign up */
        'signup_title' => ['Konto anlegen', 'Create an account', 'Créer un compte', 'Crear una cuenta', 'Crea un account', 'Account aanmaken', 'Utwórz konto', 'Vytvořit účet', 'Criar conta', 'Создать аккаунт', 'Hesap oluştur', '创建账户'],
        'signup_lead'  => [
            'Für Buchungen und um Ihre Aufenthalte wiederzufinden.',
            'For booking, and for finding your stays again.',
            'Pour réserver et retrouver vos séjours.',
            'Para reservar y volver a encontrar sus estancias.',
            'Per prenotare e ritrovare i vostri soggiorni.',
            'Om te boeken en uw verblijven terug te vinden.',
            'Do rezerwacji i odnajdywania swoich pobytów.',
            'Pro rezervace a k dohledání vašich pobytů.',
            'Para reservar e reencontrar as suas estadias.',
            'Для брони и чтобы снова найти свои поездки.',
            'Rezervasyon yapmak ve konaklamalarınızı bulmak için.',
            '用于预订，也便于查回您的入住记录。',
        ],
        'name'            => ['Name', 'Full name', 'Nom complet', 'Nombre completo', 'Nome e cognome', 'Volledige naam', 'Imię i nazwisko', 'Celé jméno', 'Nome completo', 'Полное имя', 'Ad soyad', '姓名'],
        'username'        => ['Benutzername', 'Username', 'Identifiant', 'Usuario', 'Nome utente', 'Gebruikersnaam', 'Nazwa użytkownika', 'Uživatelské jméno', 'Utilizador', 'Имя пользователя', 'Kullanıcı adı', '用户名'],
        'email'           => ['E-Mail', 'Email', 'E-mail', 'Correo', 'E-mail', 'E-mail', 'E-mail', 'E-mail', 'E-mail', 'Эл. почта', 'E-posta', '邮箱'],
        'email_help'      => ['Beides meldet Sie an', 'Either one signs you in', 'L’un ou l’autre vous connecte', 'Cualquiera de los dos sirve para entrar', 'Vanno bene entrambi per accedere', 'Beide werken om aan te melden', 'Każde z nich pozwala się zalogować', 'Přihlásit se lze obojím', 'Qualquer um serve para entrar', 'Войти можно любым из них', 'İkisiyle de giriş yapabilirsiniz', '两者均可用于登录'],
        'mobile'          => ['Mobilnummer', 'Mobile', 'Portable', 'Móvil', 'Cellulare', 'Mobiel', 'Telefon komórkowy', 'Mobil', 'Telemóvel', 'Мобильный', 'Cep telefonu', '手机号'],
        'mobile_help'     => ['Nur zu Ihrer Buchung, auch per WhatsApp', 'Only about your booking, WhatsApp included', 'Uniquement au sujet de votre réservation, WhatsApp compris', 'Solo sobre su reserva, también por WhatsApp', 'Solo per la vostra prenotazione, anche via WhatsApp', 'Alleen over uw boeking, ook via WhatsApp', 'Wyłącznie w sprawie rezerwacji, także przez WhatsApp', 'Jen k vaší rezervaci, i přes WhatsApp', 'Apenas sobre a sua reserva, também por WhatsApp', 'Только по вашей брони, в том числе в WhatsApp', 'Yalnızca rezervasyonunuz için, WhatsApp dâhil', '仅与您的预订有关，含 WhatsApp'],
        'mobile_help_admin' => ['Für dringende Rückfragen', 'For anything urgent', 'Pour les urgences', 'Para asuntos urgentes', 'Per le urgenze', 'Voor dringende zaken', 'Na pilne sprawy', 'Pro naléhavé dotazy', 'Para assuntos urgentes', 'Для срочных вопросов', 'Acil durumlar için', '用于紧急联系'],
        'optional'        => ['optional', 'optional', 'facultatif', 'opcional', 'facoltativo', 'optioneel', 'opcjonalne', 'nepovinné', 'opcional', 'необязательно', 'isteğe bağlı', '选填'],
        'password_repeat' => ['Wiederholen', 'Repeat', 'Confirmer', 'Repetir', 'Ripeti', 'Herhalen', 'Powtórz', 'Zopakovat', 'Repetir', 'Повторите', 'Tekrar', '再次输入'],
        'password_help'   => ['min. 10 Zeichen', 'at least 10 characters', 'au moins 10 caractères', 'al menos 10 caracteres', 'almeno 10 caratteri', 'minstens 10 tekens', 'co najmniej 10 znaków', 'alespoň 10 znaků', 'pelo menos 10 caracteres', 'не менее 10 символов', 'en az 10 karakter', '至少 10 个字符'],
        'signup_button'   => ['Konto anlegen', 'Create account', 'Créer le compte', 'Crear cuenta', 'Crea account', 'Account aanmaken', 'Utwórz konto', 'Vytvořit účet', 'Criar conta', 'Создать аккаунт', 'Hesap oluştur', '创建账户'],
        'have_account'    => ['Schon ein Konto?', 'Already have one?', 'Vous avez déjà un compte ?', '¿Ya tiene cuenta?', 'Hai già un account?', 'Al een account?', 'Masz już konto?', 'Už máte účet?', 'Já tem conta?', 'Уже есть аккаунт?', 'Hesabınız var mı?', '已有账户？'],
        'have_account_link' => ['Anmelden', 'Sign in', 'Se connecter', 'Iniciar sesión', 'Accedi', 'Aanmelden', 'Zaloguj się', 'Přihlásit se', 'Entrar', 'Войти', 'Giriş yap', '登录'],
        'show_password'   => ['Passwort anzeigen', 'Show password', 'Afficher le mot de passe', 'Mostrar contraseña', 'Mostra password', 'Wachtwoord tonen', 'Pokaż hasło', 'Zobrazit heslo', 'Mostrar palavra-passe', 'Показать пароль', 'Parolayı göster', '显示密码'],
        'hide_password'   => ['Passwort verbergen', 'Hide password', 'Masquer le mot de passe', 'Ocultar contraseña', 'Nascondi password', 'Wachtwoord verbergen', 'Ukryj hasło', 'Skrýt heslo', 'Ocultar palavra-passe', 'Скрыть пароль', 'Parolayı gizle', '隐藏密码'],

        /* ------------------------------------------- the first administrator */
        'admin_claim_title' => ['Dieses Konto wird Administrator', 'This account becomes the administrator', 'Ce compte deviendra administrateur', 'Esta cuenta será la de administrador', 'Questo account diventerà amministratore', 'Dit account wordt beheerder', 'To konto zostanie administratorem', 'Tento účet se stane správcem', 'Esta conta será a de administrador', 'Эта запись станет администратором', 'Bu hesap yönetici olacak', '此账户将成为管理员'],
        'admin_claim_body'  => [
            'Auf dieser Website gibt es noch keinen Administrator. Das erste hier angelegte Konto wird einer und verwaltet die Seite.',
            'There is no administrator on this site yet. The first account created here becomes one and owns the panel. After that, this form only makes guest accounts.',
            'Ce site n’a pas encore d’administrateur. Le premier compte créé ici le devient et gère le site.',
            'Este sitio aún no tiene administrador. La primera cuenta creada aquí lo será y gestionará el sitio.',
            'Questo sito non ha ancora un amministratore. Il primo account creato qui lo diventa e gestisce il sito.',
            'Deze site heeft nog geen beheerder. Het eerste account dat hier wordt aangemaakt wordt dat en beheert de site.',
            'Ta strona nie ma jeszcze administratora. Pierwsze utworzone tu konto nim zostanie i będzie zarządzać stroną.',
            'Tento web zatím nemá správce. První zde vytvořený účet se jím stane a bude web spravovat.',
            'Este site ainda não tem administrador. A primeira conta criada aqui passa a sê-lo e gere o site.',
            'На этом сайте ещё нет администратора. Первая созданная здесь запись станет им и получит управление сайтом.',
            'Bu sitede henüz bir yönetici yok. Burada oluşturulan ilk hesap yönetici olur ve siteyi yönetir.',
            '本站尚无管理员。在此创建的第一个账户将成为管理员并管理本站。',
        ],
        'admin_title'   => ['Administrator anlegen', 'Create the administrator', 'Créer l’administrateur', 'Crear el administrador', 'Crea l’amministratore', 'Beheerder aanmaken', 'Utwórz administratora', 'Vytvořit správce', 'Criar o administrador', 'Создать администратора', 'Yöneticiyi oluştur', '创建管理员'],
        'admin_lead'    => ['Das Konto, mit dem die Website bearbeitet wird.', 'The account the site is edited with.', 'Le compte avec lequel le site est modifié.', 'La cuenta con la que se edita el sitio.', 'L’account con cui si modifica il sito.', 'Het account waarmee de site wordt bewerkt.', 'Konto, którym edytuje się stronę.', 'Účet, kterým se web upravuje.', 'A conta com que o site é editado.', 'Учётная запись, которой редактируется сайт.', 'Sitenin düzenlendiği hesap.', '用于编辑本站的账户。'],
        'admin_button'  => ['Administrator anlegen', 'Create administrator', 'Créer l’administrateur', 'Crear administrador', 'Crea amministratore', 'Beheerder aanmaken', 'Utwórz administratora', 'Vytvořit správce', 'Criar administrador', 'Создать администратора', 'Yönetici oluştur', '创建管理员'],
        'first_account' => ['Erstes Konto', 'First account', 'Premier compte', 'Primera cuenta', 'Primo account', 'Eerste account', 'Pierwsze konto', 'První účet', 'Primeira conta', 'Первая запись', 'İlk hesap', '首个账户'],

        /* ----------------------------------------------------- two-factor */
        'twofactor_title' => ['Code eingeben', 'Enter the code', 'Saisir le code', 'Introduzca el código', 'Inserisci il codice', 'Code invoeren', 'Wpisz kod', 'Zadejte kód', 'Introduza o código', 'Введите код', 'Kodu girin', '输入验证码'],
        'twofactor_lead'  => [
            'Ihre Authenticator-App zeigt einen sechsstelligen Code. Ohne Telefon zur Hand funktioniert auch einer Ihrer Wiederherstellungscodes — jeder davon einmal.',
            'Your authenticator app is showing a six-digit code. If the phone is not to hand, one of your recovery codes works instead — each of those works once.',
            'Votre application d’authentification affiche un code à six chiffres. Sans le téléphone, un code de secours fait l’affaire — chacun une seule fois.',
            'Su aplicación de autenticación muestra un código de seis dígitos. Sin el teléfono, sirve uno de sus códigos de recuperación — cada uno una vez.',
            'La vostra app di autenticazione mostra un codice a sei cifre. Senza telefono va bene un codice di recupero — ognuno una volta sola.',
            'Uw authenticator-app toont een code van zes cijfers. Zonder telefoon werkt ook een herstelcode — elk daarvan één keer.',
            'Aplikacja uwierzytelniająca pokazuje sześciocyfrowy kod. Bez telefonu zadziała kod zapasowy — każdy jeden raz.',
            'Vaše ověřovací aplikace zobrazuje šestimístný kód. Bez telefonu poslouží záložní kód — každý jednou.',
            'A sua aplicação de autenticação mostra um código de seis dígitos. Sem o telemóvel, serve um código de recuperação — cada um uma vez.',
            'Приложение-аутентификатор показывает шестизначный код. Без телефона подойдёт код восстановления — каждый один раз.',
            'Kimlik doğrulama uygulamanız altı haneli bir kod gösterir. Telefon yoksa kurtarma kodlarınızdan biri işe yarar — her biri bir kez.',
            '您的验证器应用会显示六位验证码。手机不在手边时，可使用一次性恢复码。',
        ],
        'code'       => ['Code', 'Code', 'Code', 'Código', 'Codice', 'Code', 'Kod', 'Kód', 'Código', 'Код', 'Kod', '验证码'],
        'continue'   => ['Weiter', 'Continue', 'Continuer', 'Continuar', 'Continua', 'Verder', 'Dalej', 'Pokračovat', 'Continuar', 'Продолжить', 'Devam', '继续'],
        'start_over' => ['Von vorne beginnen', 'Start again', 'Recommencer', 'Empezar de nuevo', 'Ricomincia', 'Opnieuw beginnen', 'Zacznij od nowa', 'Začít znovu', 'Recomeçar', 'Начать заново', 'Baştan başla', '重新开始'],
    ];

    public function run(): void
    {
        $languages = count(self::ORDER);
        $short     = [];

        foreach (self::STRINGS as $key => $values) {
            /* A row with the wrong number of columns is a mistake worth
               hearing about rather than a silent gap in one language. */
            if (count($values) !== $languages) {
                $short[] = $key . ' (' . count($values) . '/' . $languages . ')';
                continue;
            }

            Translation::updateOrCreate(
                ['group' => 'auth', 'key' => $key],
                ['values' => array_combine(self::ORDER, $values)],
            );
        }

        $this->command?->info(
            (count(self::STRINGS) - count($short)) . ' sign-in strings loaded in ' . $languages . ' languages.'
        );

        foreach ($short as $key) {
            $this->command?->warn('  incomplete, skipped: ' . $key);
        }
    }
}
