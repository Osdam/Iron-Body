<?php

namespace Tests\Feature\Moderation;

/**
 * El medio de un estado tiene que vivir en NUESTRO Storage.
 *
 * `download_url` se guardaba tal cual y `Story::file_url` la devuelve sin
 * tocarla, así que un cliente podía publicar un estado cuyo contenido apuntaba
 * a cualquier servidor de internet. Eso rompía la moderación de raíz: no hay
 * objeto que retirar, la evidencia apunta a una ruta falsa y el contenido
 * seguiría en línea después de «eliminarlo».
 */
class StoryMediaOriginTest extends ModerationTestCase
{
    private const URL = '/api/app/stories/firebase';

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'type' => 'image',
            'firebase_path' => 'stories/1/x.jpg',
            'download_url' => $this->storageUrl('stories/1/x.jpg'),
        ], $overrides);
    }

    public function test_acepta_el_bucket_propio(): void
    {
        $member = $this->makeMember('Publicador');
        $this->acceptGuidelines($member);

        $this->postJson(self::URL, $this->payload(), $this->asMember($member))
            ->assertCreated();

        $this->assertDatabaseCount('stories', 1);
    }

    public function test_rechaza_un_medio_alojado_fuera(): void
    {
        $member = $this->makeMember('Publicador');
        $this->acceptGuidelines($member);

        $this->postJson(self::URL, $this->payload([
            'download_url' => 'https://cdn-malicioso.example/porno.mp4',
        ]), $this->asMember($member))
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_media_url');

        $this->assertDatabaseCount('stories', 0);
    }

    /** Host correcto pero de OTRO proyecto: sigue siendo contenido ajeno. */
    public function test_rechaza_otro_bucket_de_firebase(): void
    {
        $member = $this->makeMember('Publicador');
        $this->acceptGuidelines($member);

        $this->postJson(self::URL, $this->payload([
            'download_url' => 'https://firebasestorage.googleapis.com/v0/b/'
                .'proyecto-ajeno.appspot.com/o/x.jpg?alt=media',
        ]), $this->asMember($member))
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_media_url');

        $this->assertDatabaseCount('stories', 0);
    }

    public function test_rechaza_http_sin_cifrar(): void
    {
        $member = $this->makeMember('Publicador');
        $this->acceptGuidelines($member);

        $bucket = (string) config('services.firebase.storage_bucket');

        $this->postJson(self::URL, $this->payload([
            'download_url' => 'http://firebasestorage.googleapis.com/v0/b/'.$bucket.'/o/x.jpg',
        ]), $this->asMember($member))
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_media_url');
    }

    /** La ruta del objeto no puede apuntar fuera del bucket. */
    public function test_rechaza_una_ruta_con_salto_de_directorio(): void
    {
        $member = $this->makeMember('Publicador');
        $this->acceptGuidelines($member);

        $this->postJson(self::URL, $this->payload([
            'firebase_path' => 'stories/../../secretos/clave.txt',
        ]), $this->asMember($member))
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_media_path');

        $this->assertDatabaseCount('stories', 0);
    }

    public function test_acepta_una_ruta_gs_del_bucket_propio(): void
    {
        $member = $this->makeMember('Publicador');
        $this->acceptGuidelines($member);

        $bucket = (string) config('services.firebase.storage_bucket');

        $this->postJson(self::URL, $this->payload([
            'firebase_path' => 'gs://'.$bucket.'/stories/1/x.jpg',
        ]), $this->asMember($member))
            ->assertCreated();
    }

    public function test_rechaza_una_ruta_gs_de_otro_bucket(): void
    {
        $member = $this->makeMember('Publicador');
        $this->acceptGuidelines($member);

        $this->postJson(self::URL, $this->payload([
            'firebase_path' => 'gs://bucket-ajeno.appspot.com/stories/1/x.jpg',
        ]), $this->asMember($member))
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_media_path');
    }
}
