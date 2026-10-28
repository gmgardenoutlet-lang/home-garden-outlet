<?php
declare(strict_types=1);

// Stage B deliberately has no product, offer, image or sales transport.
final class AllegroSafeException extends RuntimeException
{
    public function __construct(public readonly string $kind)
    {
        $messages = [
            'config' => 'Konfiguracja Allegro jest niekompletna lub niebezpieczna.',
            'disabled' => 'OAuth Allegro jest zablokowany. Wymagane potwierdzenie konfiguracji i ochrony logów hostingu.',
            'storage' => 'Nie można bezpiecznie odczytać lub zapisać prywatnych danych Allegro.',
            'state' => 'Nieprawidłowe lub wygasłe połączenie Allegro. Rozpocznij je ponownie.',
            'session' => 'Połączenie nie należy do tej sesji administratora lub zostało wykorzystane.',
            'denied' => 'Zgoda Allegro nie została udzielona. Rozpocznij połączenie ponownie.',
            'auth' => 'Allegro odrzuciło uwierzytelnienie. Połącz konto ponownie.',
            'network' => 'Nie udało się potwierdzić odpowiedzi Allegro. Nie ponawiamy automatycznie operacji.',
            'refresh' => 'Nie udało się bezpiecznie odnowić tokena. Połącz konto ponownie.',
            'policy' => 'Etap B blokuje to żądanie. Dozwolone są tylko OAuth i odczyt własnego konta.',
            'identity' => 'Nie można potwierdzić tożsamości połączonego konta. Połącz je ponownie.',
        ];
        parent::__construct($messages[$kind] ?? $messages['config']);
    }
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/oauth.php';
