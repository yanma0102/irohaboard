<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Model\Table\InfosTable;
use Cake\TestSuite\TestCase;

/**
 * InfosTable 可視性（opened / closed）の回帰テスト
 *
 * D-28 修正: opened=NULL の下書きお知らせが受講者画面に漏洩する不具合の再発防止
 */
class InfosVisibilityTest extends TestCase
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

    // ── ヘルパーメソッド ──

    private function saveUser(string $username, string $role = 'user'): int
    {
        $Users = $this->getTableLocator()->get('Users');
        $user = $Users->save($Users->newEntity([
            'username' => $username,
            'password' => 'testpass',
            'name' => $username,
            'role' => $role,
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

    /**
     * お知らせを保存（opened/closed を明示的に指定可能）
     */
    private function saveInfo(
        string $title,
        int $userId,
        ?string $opened = null,
        ?string $closed = null,
    ): int {
        $info = $this->Infos->save($this->Infos->newEntity([
            'title' => $title,
            'body' => $title . '本文',
            'user_id' => $userId,
            'opened' => $opened,
            'closed' => $closed,
        ]));
        $this->assertNotFalse($info);

        return (int)$info->id;
    }

    private function linkInfoToGroup(int $infoId, int $groupId): void
    {
        $InfosGroups = $this->getTableLocator()->get('InfosGroups');
        $result = $InfosGroups->save($InfosGroups->newEntity([
            'info_id' => $infoId,
            'group_id' => $groupId,
        ]));
        $this->assertNotFalse($result);
    }

    private function linkUserToGroup(int $userId, int $groupId): void
    {
        $UsersGroups = $this->getTableLocator()->get('UsersGroups');
        $result = $UsersGroups->save($UsersGroups->newEntity([
            'user_id' => $userId,
            'group_id' => $groupId,
        ]));
        $this->assertNotFalse($result);
    }

    // ── テスト: opened = NULL（下書き）は閲覧不可 ──

    public function testDraftInfoIsExcludedFromGetInfos(): void
    {
        $adminId = $this->saveUser('admin1', 'admin');
        $userId = $this->saveUser('user1');

        $now = date('Y-m-d H:i:s');

        // 公開中のお知らせ
        $publicId = $this->saveInfo('公開中', $adminId, $now);

        // 下書き（opened = NULL）
        $draftId = $this->saveInfo('下書き', $adminId, null);

        $infos = $this->Infos->getInfos($userId);
        $ids = array_map(fn($i) => $i->id, $infos);

        $this->assertContains($publicId, $ids, '公開中のお知らせは表示される');
        $this->assertNotContains($draftId, $ids, '下書き（opened=NULL）は表示されない');
    }

    public function testDraftInfoIsExcludedFromGetInfoOption(): void
    {
        $adminId = $this->saveUser('admin2', 'admin');
        $userId = $this->saveUser('user2');

        $now = date('Y-m-d H:i:s');

        $publicId = $this->saveInfo('公開Option', $adminId, $now);
        $draftId = $this->saveInfo('下書きOption', $adminId, null);

        $query = $this->Infos->getInfoOption($userId);
        $rows = $query->all()->toArray();
        $ids = array_map(fn($r) => (int)$r->id, $rows);

        $this->assertContains($publicId, $ids, 'getInfoOption で公開中は表示');
        $this->assertNotContains($draftId, $ids, 'getInfoOption で下書きは非表示');
    }

    public function testDraftInfoIsExcludedFromHasRight(): void
    {
        $adminId = $this->saveUser('admin3', 'admin');
        $userId = $this->saveUser('user3');

        $now = date('Y-m-d H:i:s');

        $publicId = $this->saveInfo('公開Right', $adminId, $now);
        $draftId = $this->saveInfo('下書きRight', $adminId, null);

        $this->assertTrue($this->Infos->hasRight($userId, $publicId), '公開中にはhasRight=true');
        $this->assertFalse($this->Infos->hasRight($userId, $draftId), '下書きにはhasRight=false');
    }

    // ── テスト: opened が過去（公開中）は閲覧可 ──

    public function testPublishedInfoWithPastOpenedIsVisible(): void
    {
        $adminId = $this->saveUser('admin4', 'admin');
        $userId = $this->saveUser('user4');

        $past = date('Y-m-d H:i:s', strtotime('-1 day'));

        $infoId = $this->saveInfo('過去公開', $adminId, $past);

        $infos = $this->Infos->getInfos($userId);
        $ids = array_map(fn($i) => $i->id, $infos);

        $this->assertContains($infoId, $ids, 'opened が過去のお知らせは表示される');
    }

    // ── テスト: opened が未来（未公開予定）は閲覧不可 ──

    public function testFutureOpenedInfoIsNotVisible(): void
    {
        $adminId = $this->saveUser('admin5', 'admin');
        $userId = $this->saveUser('user5');

        $future = date('Y-m-d H:i:s', strtotime('+1 day'));

        $infoId = $this->saveInfo('未来公開', $adminId, $future);

        $infos = $this->Infos->getInfos($userId);
        $ids = array_map(fn($i) => $i->id, $infos);

        $this->assertNotContains($infoId, $ids, 'opened が未来のお知らせは表示されない');
    }

    public function testFutureOpenedInfoHasRightReturnsFalse(): void
    {
        $adminId = $this->saveUser('admin6', 'admin');
        $userId = $this->saveUser('user6');

        $future = date('Y-m-d H:i:s', strtotime('+1 day'));

        $infoId = $this->saveInfo('未来Right', $adminId, $future);

        $this->assertFalse($this->Infos->hasRight($userId, $infoId), '未来公開は hasRight=false');
    }

    // ── テスト: closed が過去（閉鎖済み）は閲覧不可 ──

    public function testClosedInfoIsNotVisible(): void
    {
        $adminId = $this->saveUser('admin7', 'admin');
        $userId = $this->saveUser('user7');

        $past = date('Y-m-d H:i:s', strtotime('-2 days'));
        $pastClosed = date('Y-m-d H:i:s', strtotime('-1 day'));

        $infoId = $this->saveInfo('閉鎖済み', $adminId, $past, $pastClosed);

        $infos = $this->Infos->getInfos($userId);
        $ids = array_map(fn($i) => $i->id, $infos);

        $this->assertNotContains($infoId, $ids, 'closed が過去（閉鎖済み）は表示されない');
    }

    // ── テスト: closed が未来（未閉鎖）は閲覧可 ──

    public function testFutureClosedInfoIsVisible(): void
    {
        $adminId = $this->saveUser('admin8', 'admin');
        $userId = $this->saveUser('user8');

        $past = date('Y-m-d H:i:s', strtotime('-1 day'));
        $futureClosed = date('Y-m-d H:i:s', strtotime('+7 days'));

        $infoId = $this->saveInfo('閉鎖予定', $adminId, $past, $futureClosed);

        $infos = $this->Infos->getInfos($userId);
        $ids = array_map(fn($i) => $i->id, $infos);

        $this->assertContains($infoId, $ids, 'closed が未来（未閉鎖）は表示される');
    }

    // ── テスト: グループ制御 + opened 条件の組み合わせ ──

    public function testGroupRestrictedDraftIsNotVisible(): void
    {
        $adminId = $this->saveUser('admin9', 'admin');
        $userId = $this->saveUser('user9');
        $groupId = $this->saveGroup('テストグループ');

        $this->linkUserToGroup($userId, $groupId);

        // グループ限定 + 公開中 → 表示される
        $now = date('Y-m-d H:i:s');
        $publicGroupId = $this->saveInfo('グループ公開', $adminId, $now);
        $this->linkInfoToGroup($publicGroupId, $groupId);

        // グループ限定 + 下書き → 表示されない
        $draftGroupId = $this->saveInfo('グループ下書き', $adminId, null);
        $this->linkInfoToGroup($draftGroupId, $groupId);

        $infos = $this->Infos->getInfos($userId);
        $ids = array_map(fn($i) => $i->id, $infos);

        $this->assertContains($publicGroupId, $ids, 'グループ限定+公開中は表示');
        $this->assertNotContains($draftGroupId, $ids, 'グループ限定+下書きは非表示');
    }

    public function testNonMemberCannotSeeGroupRestrictedInfo(): void
    {
        $adminId = $this->saveUser('admin10', 'admin');
        $userId = $this->saveUser('user10');
        $groupId = $this->saveGroup(' restricted');

        $now = date('Y-m-d H:i:s');
        $infoId = $this->saveInfo('グループ限定', $adminId, $now);
        $this->linkInfoToGroup($infoId, $groupId);

        // ユーザはグループに属していない
        $infos = $this->Infos->getInfos($userId);
        $ids = array_map(fn($i) => $i->id, $infos);

        $this->assertNotContains($infoId, $ids, 'グループ未所属ユーザには表示されない');
    }

    public function testMemberCanSeeGroupRestrictedPublishedInfo(): void
    {
        $adminId = $this->saveUser('admin11', 'admin');
        $userId = $this->saveUser('user11');
        $groupId = $this->saveGroup('テストグループ2');

        $this->linkUserToGroup($userId, $groupId);

        $now = date('Y-m-d H:i:s');
        $infoId = $this->saveInfo('グループ公開2', $adminId, $now);
        $this->linkInfoToGroup($infoId, $groupId);

        $infos = $this->Infos->getInfos($userId);
        $ids = array_map(fn($i) => $i->id, $infos);

        $this->assertContains($infoId, $ids, 'グループ所属ユーザには表示される');
    }

    // ── テスト: 全体公開（グループ未設定）+ opened の組み合わせ ──

    public function testPublicInfoWithoutGroupAndWithOpenedIsVisible(): void
    {
        $adminId = $this->saveUser('admin12', 'admin');
        $userId = $this->saveUser('user12');

        $now = date('Y-m-d H:i:s');
        $infoId = $this->saveInfo('全体公開', $adminId, $now);

        // グループ紐づけなし
        $infos = $this->Infos->getInfos($userId);
        $ids = array_map(fn($i) => $i->id, $infos);

        $this->assertContains($infoId, $ids, '全体公開+opened=now は表示');
    }

    public function testPublicInfoWithoutGroupButDraftIsNotVisible(): void
    {
        $adminId = $this->saveUser('admin13', 'admin');
        $userId = $this->saveUser('user13');

        // グループ紐づけなし + 下書き
        $infoId = $this->saveInfo('全体下書き', $adminId, null);

        $infos = $this->Infos->getInfos($userId);
        $ids = array_map(fn($i) => $i->id, $infos);

        $this->assertNotContains($infoId, $ids, '全体公開設定でも opened=NULL なら非表示');
    }

    // ── テスト: 管理者/スタッフロールでも受講者画面では同じフィルタが適用される ──

    public function testAdminUserThroughFrontEndSameFilterAsRegularUser(): void
    {
        $adminId = $this->saveUser('admin14', 'admin');

        // admin ロールのユーザでも、受講者画面経由（getInfos/getInfoOption/hasRight）では
        // opened フィルタが適用される（管理画面は別コントローラで、このメソッドを通らない）
        $adminUserId = $this->saveUser('adminAsStudent', 'admin');

        $now = date('Y-m-d H:i:s');
        $publicId = $this->saveInfo('公開管理用', $adminId, $now);
        $draftId = $this->saveInfo('下書き管理用', $adminId, null);

        $infos = $this->Infos->getInfos($adminUserId);
        $ids = array_map(fn($i) => $i->id, $infos);

        $this->assertContains($publicId, $ids, 'admin ロールでも公開中は表示');
        $this->assertNotContains($draftId, $ids, 'admin ロールでも下書きは非表示（getInfos 経由）');

        // hasRight も同様
        $this->assertTrue($this->Infos->hasRight($adminUserId, $publicId));
        $this->assertFalse($this->Infos->hasRight($adminUserId, $draftId));
    }

    // ── テスト: 複数条件の組み合わせ（公開済み + グループ限定 + 未来closed） ──

    public function testMultipleInfosMixedVisibility(): void
    {
        $adminId = $this->saveUser('admin15', 'admin');
        $userId = $this->saveUser('user15');
        $groupId = $this->saveGroup('テストグループ3');

        $this->linkUserToGroup($userId, $groupId);

        $now = date('Y-m-d H:i:s');
        $yesterday = date('Y-m-d H:i:s', strtotime('-1 day'));
        $tomorrow = date('Y-m-d H:i:s', strtotime('+1 day'));
        $futureClose = date('Y-m-d H:i:s', strtotime('+30 days'));
        $pastClose = date('Y-m-d H:i:s', strtotime('-1 day'));

        // 1. 全体公開 + 公開中 → 表示
        $id1 = $this->saveInfo('全体公開中', $adminId, $yesterday);

        // 2. 下書き → 非表示
        $id2 = $this->saveInfo('下書き', $adminId, null);

        // 3. 未来公開予定 → 非表示
        $id3 = $this->saveInfo('未来公開', $adminId, $tomorrow);

        // 4. 全体公開 + 閉鎖済み → 非表示
        $id4 = $this->saveInfo('閉鎖済み', $adminId, $yesterday, $pastClose);

        // 5. グループ限定 + 公開中 + 未閉鎖 → 表示
        $id5 = $this->saveInfo('グループ公開', $adminId, $yesterday, $futureClose);
        $this->linkInfoToGroup($id5, $groupId);

        // 6. グループ限定 + 下書き → 非表示
        $id6 = $this->saveInfo('グループ下書き', $adminId, null);
        $this->linkInfoToGroup($id6, $groupId);

        $infos = $this->Infos->getInfos($userId);
        $ids = array_map(fn($i) => $i->id, $infos);

        $this->assertContains($id1, $ids, '全体公開中');
        $this->assertNotContains($id2, $ids, '下書き');
        $this->assertNotContains($id3, $ids, '未来公開');
        $this->assertNotContains($id4, $ids, '閉鎖済み');
        $this->assertContains($id5, $ids, 'グループ公開+未閉鎖');
        $this->assertNotContains($id6, $ids, 'グループ下書き');
    }

    // ── テスト: limit パラメータが公開フィルタと併せて正常動作 ──

    public function testLimitRespectsVisibilityFilter(): void
    {
        $adminId = $this->saveUser('admin16', 'admin');
        $userId = $this->saveUser('user16');

        $yesterday = date('Y-m-d H:i:s', strtotime('-3 days'));

        // 3つ作成、うち1つは下書き
        $this->saveInfo('公開1', $adminId, $yesterday);
        $this->saveInfo('下書き', $adminId, null);
        $this->saveInfo('公開2', $adminId, $yesterday);

        $infosLimited = $this->Infos->getInfos($userId, 2);
        $this->assertCount(2, $infosLimited, 'limit=2 でも公開分から取得される');

        $infosAll = $this->Infos->getInfos($userId);
        $this->assertCount(2, $infosAll, '下書きは除外されて2件');
    }

    // ── テスト: getInfoOption のクエリ結果に下書きが含まれない ──

    public function testGetInfoOptionExcludesDraftsAndClosed(): void
    {
        $adminId = $this->saveUser('admin17', 'admin');
        $userId = $this->saveUser('user17');

        $yesterday = date('Y-m-d H:i:s', strtotime('-1 day'));
        $pastClose = date('Y-m-d H:i:s', strtotime('-1 day'));

        $visibleId = $this->saveInfo('表示', $adminId, $yesterday);
        $draftId = $this->saveInfo('下書き', $adminId, null);
        $closedId = $this->saveInfo('閉鎖', $adminId, $yesterday, $pastClose);

        $rows = $this->Infos->getInfoOption($userId)->all()->toArray();
        $ids = array_map(fn($r) => (int)$r->id, $rows);

        $this->assertContains($visibleId, $ids, 'getInfoOption に公開中が含まれる');
        $this->assertNotContains($draftId, $ids, 'getInfoOption に下書きが含まれない');
        $this->assertNotContains($closedId, $ids, 'getInfoOption に閉鎖済みが含まれない');
    }
}
