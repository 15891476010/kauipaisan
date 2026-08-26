import { DoubleRightOutlined, QuestionCircleOutlined } from '@ant-design/icons';
import { App as AntdApp, Button, Checkbox, Input, Spin, Switch, Tooltip } from 'antd';
import { useEffect, useMemo, useState } from 'react';
import { getAgentSettings, saveAgentSettings, type AgentSettingOdds, type AgentSettingProfile } from '../../api/user';
import { changeAgentPassword } from '../../api/auth';
import { apiErrorMessage } from '../../utils/request';
import { hasAgentPermission } from '../../routePermissions';

const emptyProfile: AgentSettingProfile = { username: '', display_name: '', remark: '', share_limit: '0', follow_share: 0, total_credit: '0', allocated_credit: '0', available_credit: '0', organization_level: '', interception_editable: 0, interception_notice: '' };
const showNumber = (value: string | null | undefined) => { const number = Number(value); return Number.isFinite(number) ? String(number) : '---'; };

export function SettingsPage({ lottery }: { lottery: string }) {
  const { message } = AntdApp.useApp();
  const [profile, setProfile] = useState(emptyProfile);
  const [rows, setRows] = useState<AgentSettingOdds[]>([]);
  const [amounts, setAmounts] = useState<Record<string,string>>({});
  const [followShare, setFollowShare] = useState(false);
  const [synced, setSynced] = useState<Record<string,boolean>>({});
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [activeTab, setActiveTab] = useState<'account' | 'password'>('account');
  const [oldPassword, setOldPassword] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [passwordSaving, setPasswordSaving] = useState(false);
  const interceptionEditable = profile.interception_editable === 1;
  const canSave = hasAgentPermission('settings.update');

  useEffect(() => {
    let active = true; setLoading(true); setRows([]); setAmounts({});
    void getAgentSettings({ lottery }).then((response) => {
      if (!active || !response.data.data) return;
      const data = response.data.data; setProfile(data.profile); setFollowShare(data.profile.follow_share === 1); setRows(data.odds);
      setAmounts(Object.fromEntries(data.odds.map((row) => [String(row.id), showNumber(row.interception_amount)])));
    }).catch((reason: unknown) => { if (active) void message.error(apiErrorMessage(reason, '设置加载失败')); })
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [lottery, message]);

  const groups = useMemo(() => {
    const result: Array<{ name: string; rows: AgentSettingOdds[] }> = [];
    for (const row of rows) { const name = row.category || row.name; const last = result[result.length - 1]; if (last?.name === name) last.rows.push(row); else result.push({ name, rows: [row] }); }
    return result;
  }, [rows]);
  const allSynced = groups.length > 0 && groups.every((group) => Boolean(synced[group.name]));
  const changeAmount = (row: AgentSettingOdds, value: string) => {
    if (value !== '' && !/^\d*(?:\.\d{0,2})?$/.test(value)) return;
    const syncKey = row.category || row.name;
    setAmounts((current) => { const next={...current,[String(row.id)]:value}; if(synced[syncKey]) for(const item of rows) if((item.category || item.name)===syncKey) next[String(item.id)]=value; return next; });
  };
  const submit = async () => {
    if (!interceptionEditable) return void message.warning(profile.interception_notice || '当前层级不能设置拦货金额');
    setSaving(true);
    try { await saveAgentSettings({ lottery, follow_share: followShare ? 1 : 0, amounts: Object.fromEntries(Object.entries(amounts).map(([key,value]) => [key,value===''?'0':value])) }); setProfile((current) => ({...current,follow_share:followShare?1:0})); void message.success('设置保存成功'); }
    catch(reason: unknown) { void message.error(apiErrorMessage(reason,'保存失败')); }
    finally { setSaving(false); }
  };
  const submitPassword = async () => {
    if (!oldPassword) return void message.warning('请输入当前密码');
    if (newPassword.length < 6) return void message.warning('新密码不能少于6位');
    if (newPassword === profile.username) return void message.warning('密码不能跟账号相同');
    if (newPassword !== confirmPassword) return void message.warning('两次输入的新密码不一致');
    setPasswordSaving(true);
    try {
      await changeAgentPassword({ old_password: oldPassword, password: newPassword, confirm_password: confirmPassword });
      setOldPassword(''); setNewPassword(''); setConfirmPassword('');
      void message.success('密码修改成功'); setActiveTab('account');
    } catch (reason: unknown) { void message.error(apiErrorMessage(reason, '密码修改失败')); }
    finally { setPasswordSaving(false); }
  };

  return <section className="agent-settings-page">
    <div className="agent-settings-location">
      <div className="agent-settings-path"><strong>位置</strong><DoubleRightOutlined /><span>设置</span><DoubleRightOutlined /><span>{activeTab === 'account' ? '账号设置' : '修改密码'}</span></div>
      <nav className="agent-settings-tabs"><button type="button" className={activeTab === 'account' ? 'active' : ''} onClick={() => setActiveTab('account')}>账号设置</button><button type="button" className={activeTab === 'password' ? 'active' : ''} onClick={() => setActiveTab('password')}>修改密码</button></nav>
    </div>
    {loading ? <div className="agent-settings-loading"><Spin /></div> : <>
      {activeTab === 'password' ? <div className="agent-settings-password-panel"><div className="agent-settings-password-form"><label><span>当前密码</span><Input.Password value={oldPassword} onChange={(event) => setOldPassword(event.target.value)} autoComplete="current-password" /></label><label><span>新密码</span><Input.Password value={newPassword} onChange={(event) => setNewPassword(event.target.value)} autoComplete="new-password" /></label><label><span>确认新密码</span><Input.Password value={confirmPassword} onChange={(event) => setConfirmPassword(event.target.value)} autoComplete="new-password" /></label><small>新密码至少 6 位，不能与账号相同，并需包含数字和字母。</small><Button type="primary" loading={passwordSaving} onClick={() => void submitPassword()}>保存密码</Button></div></div> : <>
      <div className="agent-settings-profile">
        <ProfileItem label="账号" value={profile.username || '---'} />
        <ProfileItem label="代号" value={profile.display_name || '---'} />
        <ProfileItem label="备注" value={profile.remark || '---'} />
        <ProfileItem label="占成上限" value={showNumber(profile.share_limit)} />
        <div className="agent-settings-profile-item follow"><Tooltip title="开启后将跟随总后台吃货；总后台该号码已吃满时，本代理停止继续吃货。"><span><QuestionCircleOutlined /> 随盘占成</span></Tooltip><Switch aria-label="是否跟随总后台吃货" checked={followShare} disabled={!interceptionEditable || !canSave} onChange={setFollowShare} /></div>
      </div>
      <div className="agent-settings-credit">
        <ProfileItem label="总信用额度" value={showNumber(profile.total_credit)} tone="orange" />
        <ProfileItem label="已分配信用额度" value={showNumber(profile.allocated_credit)} tone="red" />
        <ProfileItem label="可分配信用额度" value={showNumber(profile.available_credit)} tone="green" />
      </div>
      {!interceptionEditable && <div className="credit-allocation-warning">{profile.interception_notice}</div>}
      <div className="agent-settings-actions"><span>{interceptionEditable ? '拦货金额设置100 = 本代理每个号码可以独立拦到100' : '以下为本站玩法限额，只能在直属代理账号中设置拦货金额'}</span>{interceptionEditable && canSave && <button type="button" disabled={saving} onClick={submit}>{saving?'保存中':'提 交'}</button>}</div>
      <div className="agent-settings-table-wrap"><table className="agent-settings-table"><thead><tr><th>类别</th><th>最小下注</th><th>赔率上限</th><th>拦货金额（同 <Checkbox checked={allSynced} disabled={!interceptionEditable || !canSave} onChange={(event) => setSynced(Object.fromEntries(groups.map((group) => [group.name, event.target.checked])))} />）</th><th>单注上限</th><th>单项上限</th></tr></thead><tbody>{groups.flatMap((group) => {
        const categoryRow = group.rows.length > 1 ? <tr className="settings-category-row" key={`category-${group.name}`}><td>{group.name}</td><td /><td /><td>{interceptionEditable && canSave ? <>（同 <Checkbox checked={Boolean(synced[group.name])} onChange={(event) => setSynced((current) => ({...current,[group.name]:event.target.checked}))} />）</> : '—'}</td><td /><td /></tr> : null;
        return [categoryRow,...group.rows.map((row) => <tr key={row.id}><td>{row.name}</td><td>{showNumber(row.min_bet)}</td><td className="settings-odds">{showNumber(row.odds_limit)}</td><td>{interceptionEditable && canSave ? <Input className="settings-amount-input" inputMode="decimal" value={amounts[String(row.id)] ?? '0'} onChange={(event) => changeAmount(row,event.target.value)} /> : '—'}</td><td>{showNumber(row.single_bet_limit)}</td><td>{showNumber(row.single_item_limit)}</td></tr>)];
      })}</tbody></table></div>
      </>}
    </>}
  </section>;
}

function ProfileItem({ label, value, tone }: { label: string; value: string; tone?: string }) { return <div className="agent-settings-profile-item"><span>{label}</span><strong className={tone || ''}>{value}</strong></div>; }
