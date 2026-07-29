"use client";

import React, { FormEvent, useEffect, useMemo, useState } from "react";
import "../globals.css";
import "./merch.css";
import { withBasePath } from "../utils/withBasePath";

type MerchItem = {
	id: string;
	name: string;
	category: string;
	price_cents: number;
	imageUrl: string;
	created_at?: number;
	updated_at?: number;
};

type Timeslot = {
	id: string;
	label: string;
	start_at: number;
	end_at: number;
	location?: string;
};

type ApiResp = {
	ok: boolean;
	items: MerchItem[];
	timeslots: Timeslot[];
	generatedAt?: number;
	error?: string;
};

function formatEuroFromCents(cents: number) {
	const value = (Number.isFinite(cents) ? cents : 0) / 100;
	return `${value.toFixed(2)} €`;
}

function formatTimeslot(slot: Timeslot) {
	const start = new Date(slot.start_at * 1000);
	const end = new Date(slot.end_at * 1000);
	const date = start.toLocaleDateString("pt-PT", {
		day: "2-digit",
		month: "2-digit",
		year: "numeric",
	});
	const startTime = start.toLocaleTimeString("pt-PT", { hour: "2-digit", minute: "2-digit" });
	const endTime = end.toLocaleTimeString("pt-PT", { hour: "2-digit", minute: "2-digit" });
	const location = slot.location?.trim() ? ` · ${slot.location.trim()}` : "";
	return `${slot.label} · ${date} · ${startTime} - ${endTime}${location}`;
}

function buildMerchMailto(params: {
	name: string;
	email: string;
	item: string;
	paymentMethod: string;
	timeslot: string;
}) {
	const recipient = "nebist.utl@gmail.com";
	const subject = "[MERCH]: Encomenda de Merch NEB";
	const body = [
		"Nova encomenda de merch",
		"",
		`Nome: ${params.name}`,
		`Email: ${params.email}`,
		`Artigo: ${params.item}`,
		`Método de pagamento: ${params.paymentMethod}`,
		`Horário escolhido: ${params.timeslot}`,
	].join("\r\n");

	return `mailto:${recipient}?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`;
}

async function tryFetchJson<T>(urls: string[]): Promise<T> {
	let lastErr: unknown = null;
	for (const url of urls) {
		try {
			const res = await fetch(url, { cache: "no-store" });
			if (!res.ok) throw new Error(`HTTP ${res.status}`);
			return (await res.json()) as T;
		} catch (e) {
			lastErr = e;
		}
	}
	throw lastErr ?? new Error("Falha ao carregar merch");
}

export default function MerchPage() {
	const [data, setData] = useState<ApiResp | null>(null);
	const [error, setError] = useState<string>("");
	const [categoryQuery, setCategoryQuery] = useState<string>("");
	const [selectedItem, setSelectedItem] = useState<string>("");
	const [orderName, setOrderName] = useState<string>("");
	const [orderEmail, setOrderEmail] = useState<string>("");
	const [paymentMethod, setPaymentMethod] = useState<string>("MBWay");
	const [selectedTimeslot, setSelectedTimeslot] = useState<string>("");
	const [orderState, setOrderState] = useState<{ type: "idle" | "loading" | "success" | "error"; message: string }>({
		type: "idle",
		message: "",
	});

	useEffect(() => {
		let cancelled = false;

		(async () => {
			setError("");
			const candidates: string[] = [];
			candidates.push(new URL("../data/scripts/list-merch.php", window.location.href).toString());
			candidates.push(new URL(withBasePath("/data/scripts/list-merch.php"), window.location.origin).toString());

			const pathname = window.location.pathname;
			const prefix = pathname.replace(/\/merch\/?$/, "");
			if (prefix !== pathname) {
				candidates.push(new URL(`${prefix}/data/scripts/list-merch.php`, window.location.origin).toString());
			}

			try {
				const response = await tryFetchJson<ApiResp>(candidates);
				if (cancelled) return;

				if (!response || response.ok !== true || !Array.isArray(response.items) || !Array.isArray(response.timeslots)) {
					throw new Error("Payload de merch inválido.");
				}

				setData(response);
				if (response.items.length > 0) {
					setSelectedItem(response.items[0].id);
				}
				if (response.timeslots.length > 0) {
					setSelectedTimeslot(response.timeslots[0].id);
				}
			} catch (e: unknown) {
				if (cancelled) return;
				setError(String((e as Error)?.message ?? e));
			}
		})();

		return () => {
			cancelled = true;
		};
	}, []);

	const categories = useMemo(() => {
		const items = data?.items ?? [];
		const query = categoryQuery.trim().toLowerCase();
		const grouped = new Map<string, MerchItem[]>();

		for (const item of items) {
			const category = item.category.trim();
			if (!category) continue;
			if (query && !category.toLowerCase().includes(query)) continue;
			const list = grouped.get(category) ?? [];
			list.push(item);
			grouped.set(category, list);
		}

		return Array.from(grouped.entries())
			.map(([category, itemsInCategory]) => ({
				category,
				items: [...itemsInCategory].sort((a, b) => a.name.localeCompare(b.name, "pt-PT")),
			}))
			.sort((a, b) => a.category.localeCompare(b.category, "pt-PT"));
	}, [data, categoryQuery]);

	const timeslots = data?.timeslots ?? [];
	const items = data?.items ?? [];

	useEffect(() => {
		if (selectedItem && items.some((item) => item.id === selectedItem)) return;
		if (items.length > 0) setSelectedItem(items[0].id);
	}, [items, selectedItem]);

	useEffect(() => {
		if (selectedTimeslot && timeslots.some((slot) => slot.id === selectedTimeslot)) return;
		if (timeslots.length > 0) setSelectedTimeslot(timeslots[0].id);
	}, [timeslots, selectedTimeslot]);

	async function handleOrderSubmit(event: FormEvent<HTMLFormElement>) {
		event.preventDefault();
		setOrderState({ type: "loading", message: "A abrir o teu email..." });

		const selectedItemName = items.find((item) => item.id === selectedItem)?.name ?? "";
		const selectedSlotLabel = timeslots.find((slot) => slot.id === selectedTimeslot);

		try {
			const mailtoUrl = buildMerchMailto({
				name: orderName.trim(),
				email: orderEmail.trim(),
				item: selectedItemName,
				paymentMethod,
				timeslot: selectedSlotLabel ? formatTimeslot(selectedSlotLabel) : "",
			});

			window.location.href = mailtoUrl;

			setOrderState({
				type: "success",
				message: "Email preparado. O teu cliente de email deve abrir com a encomenda já preenchida.",
			});
			return;
		} catch (e: unknown) {
			setOrderState({
				type: "error",
				message: String((e as Error)?.message ?? e),
			});
		}
	}

	return (
		<main className="merchPage" role="main" aria-label="Merch NEB">
			<header className="merchHero">
				<p className="merchEyebrow">Loja NEB</p>
				<h1 className="merchTitle">Merch do NEB</h1>
				<p className="merchSubtitle">
					Explora os artigos disponíveis por categoria, escolhe o teu favorito e envia a tua encomenda.
				</p>
			</header>

			<section className="merchSearchPanel" aria-label="Pesquisa por categoria">
				<label className="merchSearchField">
					<span>Pesquisar categoria</span>
					<input
						type="search"
						value={categoryQuery}
						onChange={(e) => setCategoryQuery(e.target.value)}
						placeholder="Ex.: T-shirts, Hoodies, Acessórios"
					/>
				</label>
			</section>

			{error ? <div className="merchError">{error}</div> : null}

			{!error && categories.length === 0 ? (
				<div className="merchEmpty">Não existem artigos disponíveis para a pesquisa atual.</div>
			) : null}

			{!error && categories.length > 0 ? (
				<section className="merchCatalog" aria-label="Catálogo de merch por categoria">
					{categories.map((group) => (
						<section key={group.category} className="merchCategorySection" aria-label={`Categoria ${group.category}`}>
							<div className="merchCategoryHeader">
								<h2>{group.category}</h2>
								<span>{group.items.length} artigo(s)</span>
							</div>

							<div className="merchItemsGrid">
								{group.items.map((item) => (
									<article key={item.id} className="merchItemCard">
										<div className="merchItemImageWrap">
											<img src={withBasePath(item.imageUrl)} alt={item.name} className="merchItemImage" loading="lazy" />
										</div>

										<div className="merchItemBody">
											<h3>{item.name}</h3>
											<p className="merchItemCategory">{item.category}</p>
											<div className="merchItemPrice">{formatEuroFromCents(item.price_cents)}</div>
										</div>
									</article>
								))}
							</div>
						</section>
					))}
				</section>
			) : null}

			<section className="merchOrderSection" aria-label="Comprar merch">
				<div className="merchOrderIntro">
					<h2>Encomendar merch</h2>
					<p>
						Preenche o formulário abaixo e vamos abrir o teu cliente de email com a encomenda já preparada.
					</p>
				</div>

				<form className="merchOrderForm" onSubmit={handleOrderSubmit}>
					<label className="orderField">
						<span>Nome</span>
						<input type="text" value={orderName} onChange={(e) => setOrderName(e.target.value)} required />
					</label>

					<label className="orderField">
						<span>Email</span>
						<input type="email" value={orderEmail} onChange={(e) => setOrderEmail(e.target.value)} required />
					</label>

					<label className="orderField">
						<span>Artigo</span>
						<select value={selectedItem} onChange={(e) => setSelectedItem(e.target.value)} required>
							{items.map((item) => (
								<option key={item.id} value={item.id}>
									{item.name} · {item.category} · {formatEuroFromCents(item.price_cents)}
								</option>
							))}
						</select>
					</label>

					<label className="orderField">
						<span>Método de pagamento</span>
						<select value={paymentMethod} onChange={(e) => setPaymentMethod(e.target.value)} required>
							<option value="MBWay">MBWay</option>
							<option value="Dinheiro (em mão)">Dinheiro (em mão)</option>
						</select>
					</label>

					<label className="orderField orderFieldFull">
						<span>Horário de recolha</span>
						<select value={selectedTimeslot} onChange={(e) => setSelectedTimeslot(e.target.value)} required>
							{timeslots.map((slot) => (
								<option key={slot.id} value={slot.id}>
									{formatTimeslot(slot)}
								</option>
							))}
						</select>
					</label>

					<div className="merchOrderActions">
						<button className="merchOrderButton" type="submit" disabled={orderState.type === "loading" || items.length === 0 || timeslots.length === 0}>
							{orderState.type === "loading" ? "A enviar..." : "Enviar encomenda"}
						</button>
					</div>

					{items.length === 0 || timeslots.length === 0 ? (
						<div className="merchFormNote">É preciso existir pelo menos um artigo e um horário para enviar encomendas.</div>
					) : null}

					{orderState.type === "success" ? <div className="merchMessage success">{orderState.message}</div> : null}
					{orderState.type === "error" ? <div className="merchMessage error">{orderState.message}</div> : null}
				</form>
			</section>
		</main>
	);
}
