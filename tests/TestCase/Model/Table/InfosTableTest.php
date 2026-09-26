<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Model\Table\InfosTable;
use Cake\TestSuite\TestCase;

/**
 * InfosTable のテスト
 */
class InfosTableTest extends TestCase
{
    protected InfosTable $Infos;

    public function setUp(): void
    {
        parent::setUp();
        $this->Infos = $this->getTableLocator()->get('Infos');
        $this->Infos->deleteAll('1 = 1');
        $this->getTableLocator()->get('InfosGroups')->deleteAll('1 = 1');
        $this->getTableLocator()->get('Users')->deleteAll('1 = 1');
        $this->getTableLocator()->get('Groups')->deleteAll('1 = 1');
        $this->getTableLocator()->get('UsersGroups')->deleteAll('1 = 1');
    }

    public function tearDown(): void
    {
        unset($this->Infos);
        parent::tearDown();
    }

    private function saveUser(string $username = 'infouser'): int
    {
        $Users = $this->getTableLocator()->get('Users');
        $user = $Users->save($Users->newEntity([
            'username' => $username,
            'password' => 'testpass',
            'name' => 'お知らせユーザ',
            'role' => 'user',
        ]));
        $this->assertNotFalse($user);

        return (int)$user->id;
    }

    private function saveGroup(string $title): int
    {
        $Groups = $this->getTableLocator()->get('Groups');
        $group = $Groups->save($Groups->newEntity(['title' => $title]));
        $this->assertNotFalse($group);

        return (int)$group->id;
    }

    private function saveInfo(string $title, int $userId): int
    {
        $info = $this->Infos->save($this->Infos->newEntity([
            'title' => $title,
            'body' => '本文',
            'user_id' => $userId,
            'opened' => date('Y-m-d H:i:s'),
        ]));
        $this->assertNotFalse($info);

        return (int)$info->id;
    }

    public function testGetInfosForUserWithoutGroup(): void
    {
        $adminId = $this->saveUser('infoadmin');
        $userId = $this->saveUser('infouser1');

        $this->saveInfo('全員向け1', $adminId);
        $groupId = $this->saveGroup('内部グループ');
        $limitedInfoId = $this->saveInfo('限定向け', $adminId);

        $InfosGroups = $this->getTableLocator()->get('InfosGroups');
        $InfosGroups->save($InfosGroups->newEntity([
            'info_id' => $limitedInfoId,
            'group_id' => $groupId,
        ]));

        $infos = $this->Infos->getInfos($userId);
        $titles = array_map(fn($i) => $i->title, $infos);

        $this->assertContains('全員向け1', $titles);
        $this->assertNotContains('限定向け', $titles, 'グループ未所属ユーザは限定お知らせを見られない');
    }

    public function testGetInfosForUserWithGroup(): void
    {
        $adminId = $this->saveUser('infoadmin2');
        $userId = $this->saveUser('infouser2');

        $this->saveInfo('全員向け2', $adminId);

        $groupId = $this->saveGroup('内部グループ');
        $limitedInfoId = $this->saveInfo('限定向け2', $adminId);

        $InfosGroups = $this->getTableLocator()->get('InfosGroups');
        $InfosGroups->save($InfosGroups->newEntity([
            'info_id' => $limitedInfoId,
            'group_id' => $groupId,
        ]));

        $UsersGroups = $this->getTableLocator()->get('UsersGroups');
        $UsersGroups->save($UsersGroups->newEntity([
            'user_id' => $userId,
            'group_id' => $groupId,
        ]));

        $infos = $this->Infos->getInfos($userId);
        $titles = array_map(fn($i) => $i->title, $infos);

        $this->assertContains('全員向け2', $titles);
        $this->assertContains('限定向け2', $titles, '所属グループ向けお知らせも見られる');
    }

    public function testGetInfosWithLimit(): void
    {
        $adminId = $this->saveUser('infoadmin3');
        $userId = $this->saveUser('infouser3');

        $this->saveInfo('お知らせ1', $adminId);
        $this->saveInfo('お知らせ2', $adminId);
        $this->saveInfo('お知らせ3', $adminId);

        $infos = $this->Infos->getInfos($userId, 2);
        $this->assertCount(2, $infos, 'limit が効く');

        $infosAll = $this->Infos->getInfos($userId);
        $this->assertCount(3, $infosAll);
    }

    public function testHasRight(): void
    {
        $adminId = $this->saveUser('infoadmin4');
        $userId = $this->saveUser('infouser4');

        $publicInfoId = $this->saveInfo('全員向け', $adminId);
        $this->assertTrue($this->Infos->hasRight($userId, $publicInfoId), '全員向けは閲覧可');

        $groupId = $this->saveGroup('内部グループ');
        $limitedInfoId = $this->saveInfo('限定向け', $adminId);

        $InfosGroups = $this->getTableLocator()->get('InfosGroups');
        $InfosGroups->save($InfosGroups->newEntity([
            'info_id' => $limitedInfoId,
            'group_id' => $groupId,
        ]));

        $this->assertFalse($this->Infos->hasRight($userId, $limitedInfoId), '未所属グループ限定は不可');

        $UsersGroups = $this->getTableLocator()->get('UsersGroups');
        $UsersGroups->save($UsersGroups->newEntity([
            'user_id' => $userId,
            'group_id' => $groupId,
        ]));

        $this->assertTrue($this->Infos->hasRight($userId, $limitedInfoId), '所属後は閲覧可');
    }
}